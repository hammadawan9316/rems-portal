<?php

namespace App\Libraries;

use Config\Square;

class SquareService
{
    private Square $config;

    public function __construct()
    {
        /** @var Square $square */
        $square = config('Square');
        $this->config = $square;
    }

    public function isConfigured(): bool
    {
        return $this->config->accessToken !== '' && $this->config->locationId !== '';
    }

    public function getApiVersion(): string
    {
        return $this->config->apiVersion;
    }

    /**
     * @return array{id:string,created:bool}
     */
    public function findOrCreateCustomer(string $name, string $email, ?string $phone = null): array
    {
        $existing = $this->searchCustomerByEmail($email);
        if ($existing !== null) {
            return ['id' => $existing, 'created' => false];
        }

        $payload = [
            'given_name' => $name,
            'email_address' => $email,
        ];

        if (!empty($phone)) {
            $payload['phone_number'] = $phone;
        }

        $response = $this->request('POST', '/v2/customers', $payload);
        $id = (string) ($response['customer']['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Square customer creation failed.');
        }

        return ['id' => $id, 'created' => true];
    }

    /**
     * Create draft estimate represented as a Square draft invoice for a project.
     *
     * @return array{order_id:string,estimate_id:string,status:string}
     */
    public function createDraftEstimateForProject(
        int $projectId,
        string $customerId,
        string $projectTitle,
        string $description,
        array $projectData = [],
        array $fileLinks = [],
        ?int $estimatedAmountCents = null
    ): array {
        $pricingSummary = $this->buildPricingSummary($projectData, $estimatedAmountCents);

        $details = [
            'project_title' => $projectTitle,
            'project_description' => $description,
            'category' => $projectData['category'] ?? null,
            'services' => $projectData['services'] ?? null,
            'scope' => $projectData['scope'] ?? null,
            'estimate_type' => $projectData['estimate_type'] ?? null,
            'plans_url' => $projectData['plans_url'] ?? null,
            'zip_code' => $projectData['zip_code'] ?? null,
            'deadline' => $projectData['deadline'] ?? null,
            'delivery_date' => $projectData['delivery_date'] ?? null,
            'deadline_date' => $projectData['deadline_date'] ?? null,
            'estimated_amount' => $estimatedAmountCents,
        ];

        $detailsJson = [];
        foreach ($details as $key => $value) {
            $formatted = $this->formatProjectField($key, $value);
            if ($formatted !== null) {
                $detailsJson[$key] = $value;
            }
        }

        $fileLinkList = [];
        foreach ($fileLinks as $link) {
            $candidate = trim((string) $link);
            if ($candidate !== '') {
                $fileLinkList[] = $candidate;
            }
        }

        $notePayload = [
            'project_id' => $projectId,
            'project_title' => $projectTitle,
            'project_description' => $description,
            'details' => $detailsJson,
            'pricing_summary' => $pricingSummary,
            'file_links' => $fileLinkList,
        ];
        $note = json_encode($notePayload, JSON_UNESCAPED_SLASHES);
        $lineAmount = $this->calculateProjectLineAmount($projectData, $estimatedAmountCents);

        $lineItem = [
            'name' => $projectTitle,
            'quantity' => '1',
            'note' => $note,
            'base_price_money' => [
                'amount' => $lineAmount,
                'currency' => $this->config->currency,
            ],
        ];

        $orderPayload = [
            'idempotency_key' => $this->idempotencyKey('order', $projectId),
            'order' => [
                'location_id' => $this->config->locationId,
                'customer_id' => $customerId,
                'reference_id' => 'project-' . $projectId,
                'line_items' => [
                    $lineItem,
                ],
            ],
        ];

        $orderRes = $this->request('POST', '/v2/orders', $orderPayload);
        $orderId = (string) ($orderRes['order']['id'] ?? '');
        if ($orderId === '') {
            throw new \RuntimeException('Square order creation failed for project estimate.');
        }

        $invoicePayload = [
            'idempotency_key' => $this->idempotencyKey('invoice', $projectId),
            'invoice' => [
                'location_id' => $this->config->locationId,
                'order_id' => $orderId,
                'primary_recipient' => [
                    'customer_id' => $customerId,
                ],
                'delivery_method' => 'EMAIL',
                'title' => 'Estimate for ' . $projectTitle,
                'description' => 'Draft estimate linked to project #' . $projectId,
                'accepted_payment_methods' => [
                    'card' => true,
                    'square_gift_card' => false,
                    'bank_account' => false,
                    'buy_now_pay_later' => false,
                    'cash_app_pay' => false,
                ],
                'payment_requests' => [
                    [
                        'request_type' => 'BALANCE',
                        'due_date' => date('Y-m-d', strtotime('+7 days')),
                    ],
                ],
            ],
        ];

        $invoiceRes = $this->request('POST', '/v2/invoices', $invoicePayload);
        $invoiceId = (string) ($invoiceRes['invoice']['id'] ?? '');
        $status = (string) ($invoiceRes['invoice']['status'] ?? 'DRAFT');

        if ($invoiceId === '') {
            throw new \RuntimeException('Square estimate/invoice draft creation failed.');
        }

        return [
            'order_id' => $orderId,
            'estimate_id' => $invoiceId,
            'status' => $status,
        ];
    }

    /**
     * Create one Square draft invoice for a quotation with multiple projects.
     *
     * @param array<string, mixed> $quotation
     * @param array<int, array{project_id:int,project_title:string,project_description:string,project_data:array<string,mixed>,file_links:array<int,string>,estimated_amount_cents:?int}> $projects
     * @return array{order_id:string,estimate_id:string,status:string}
     */
    public function createDraftEstimateForQuotation(
        int $quotationId,
        string $customerId,
        string $quotationTitle,
        array $quotation,
        array $projects
    ): array {
        $lineItems = [];

        foreach ($projects as $project) {
            $projectId = (int) ($project['project_id'] ?? 0);
            $projectTitle = (string) ($project['project_title'] ?? 'Project Estimate');
            $projectDescription = (string) ($project['project_description'] ?? '');
            $projectData = is_array($project['project_data'] ?? null) ? $project['project_data'] : [];
            $fileLinks = is_array($project['file_links'] ?? null) ? $project['file_links'] : [];
            $estimatedAmountCents = isset($project['estimated_amount_cents']) ? (int) $project['estimated_amount_cents'] : null;
            $pricingSummary = $this->buildPricingSummary($projectData, $estimatedAmountCents);

            $details = [
                'project_title' => $projectTitle,
                'project_description' => $projectDescription,
                'category' => $projectData['category'] ?? null,
                'services' => $projectData['services'] ?? null,
                'scope' => $projectData['scope'] ?? null,
                'estimate_type' => $projectData['estimate_type'] ?? null,
                'plans_url' => $projectData['plans_url'] ?? null,
                'zip_code' => $projectData['zip_code'] ?? null,
                'deadline' => $projectData['deadline'] ?? null,
                'delivery_date' => $projectData['delivery_date'] ?? null,
                'deadline_date' => $projectData['deadline_date'] ?? null,
                'estimated_amount' => $estimatedAmountCents,
                'payment_type' => $projectData['payment_type'] ?? null,
                'hourly_hours' => $projectData['hourly_hours'] ?? null,
                'discount_type' => $projectData['discount_type'] ?? null,
                'discount_value' => $projectData['discount_value'] ?? null,
                'discount_scope' => $projectData['discount_scope'] ?? null,
            ];

            $detailsJson = [];
            foreach ($details as $key => $value) {
                $formatted = $this->formatProjectField($key, $value);
                if ($formatted !== null) {
                    $detailsJson[$key] = $value;
                }
            }

            $fileLinkList = [];
            foreach ($fileLinks as $link) {
                $candidate = trim((string) $link);
                if ($candidate !== '') {
                    $fileLinkList[] = $candidate;
                }
            }

            $notePayload = [
                'project_id' => $projectId,
                'project_title' => $projectTitle,
                'project_description' => $projectDescription,
                'details' => $detailsJson,
                'pricing_summary' => $pricingSummary,
                'file_links' => $fileLinkList,
            ];
            $note = json_encode($notePayload, JSON_UNESCAPED_SLASHES);

            $lineItems[] = [
                'name' => $projectTitle,
                'quantity' => '1',
                'note' => $note,
                'base_price_money' => [
                    'amount' => $this->calculateProjectLineAmount($projectData, $estimatedAmountCents),
                    'currency' => $this->config->currency,
                ],
            ];
        }

        if ($lineItems === []) {
            throw new \RuntimeException('Cannot create Square quotation invoice without project line items.');
        }

        $orderPayload = [
            'idempotency_key' => $this->idempotencyKey('quotation-order', $quotationId),
            'order' => [
                'location_id' => $this->config->locationId,
                'customer_id' => $customerId,
                'reference_id' => 'quotation-' . $quotationId,
                'line_items' => $lineItems,
            ],
        ];

        $orderRes = $this->request('POST', '/v2/orders', $orderPayload);
        $orderId = (string) ($orderRes['order']['id'] ?? '');
        if ($orderId === '') {
            throw new \RuntimeException('Square order creation failed for quotation invoice.');
        }

        $invoicePayload = [
            'idempotency_key' => $this->idempotencyKey('quotation-invoice', $quotationId),
            'invoice' => [
                'location_id' => $this->config->locationId,
                'order_id' => $orderId,
                'primary_recipient' => [
                    'customer_id' => $customerId,
                ],
                'delivery_method' => 'EMAIL',
                'title' => 'Quotation ' . $quotationTitle,
                'description' => 'Draft invoice linked to quotation #' . $quotationId . ' | quotation_id:' . $quotationId,
                'accepted_payment_methods' => [
                    'card' => true,
                    'square_gift_card' => false,
                    'bank_account' => false,
                    'buy_now_pay_later' => false,
                    'cash_app_pay' => false,
                ],
                'payment_requests' => [
                    [
                        'request_type' => 'BALANCE',
                        'due_date' => date('Y-m-d', strtotime('+7 days')),
                    ],
                ],
            ],
        ];

        $firstProject = $projects[0] ?? [];
        log_message('debug', 'Square quotation invoice prepared. quotation_id={quotationId}, payment_type={paymentType}, hourly_hours={hourlyHours}, line_count={lineCount}, title={title}', [
            'quotationId' => $quotationId,
            'paymentType' => strtolower(trim((string) ($firstProject['project_data']['payment_type'] ?? 'fixed_rate'))),
            'hourlyHours' => $firstProject['project_data']['hourly_hours'] ?? null,
            'lineCount' => count($lineItems),
            'title' => $quotationTitle,
        ]);

        $invoiceRes = $this->request('POST', '/v2/invoices', $invoicePayload);
        $invoiceId = (string) ($invoiceRes['invoice']['id'] ?? '');
        $status = (string) ($invoiceRes['invoice']['status'] ?? 'DRAFT');

        if ($invoiceId === '') {
            throw new \RuntimeException('Square quotation invoice draft creation failed.');
        }

        return [
            'order_id' => $orderId,
            'estimate_id' => $invoiceId,
            'status' => $status,
        ];
    }

    /**
     * @return array{invoices:array<int, array<string,mixed>>,cursor:?string,count:int}
     */
    public function listInvoices(int $limit = 10, ?string $cursor = null, ?string $paymentStatus = null): array
    {
        $safeLimit = max(1, min($limit, 100));

        $payload = [
            'query' => [
                'filter' => [
                    'location_ids' => [$this->config->locationId],
                ],
            ],
            'limit' => $safeLimit,
        ];

        if (is_string($cursor) && trim($cursor) !== '') {
            $payload['cursor'] = trim($cursor);
        }

        $response = $this->request('POST', '/v2/invoices/search', $payload);
        $invoices = is_array($response['invoices'] ?? null) ? $response['invoices'] : [];
        $invoices = $this->filterInvoicesByPaymentStatus($invoices, $paymentStatus);
        $nextCursor = isset($response['cursor']) ? trim((string) $response['cursor']) : null;

        return [
            'invoices' => $invoices,
            'cursor' => $nextCursor !== '' ? $nextCursor : null,
            'count' => count($invoices),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getInvoiceWithAnalysis(string $invoiceId): array
    {
        $normalizedInvoiceId = trim($invoiceId);
        if ($normalizedInvoiceId === '') {
            throw new \InvalidArgumentException('Invoice ID is required.');
        }

        $invoiceResponse = $this->request('GET', '/v2/invoices/' . $normalizedInvoiceId);
        $invoice = is_array($invoiceResponse['invoice'] ?? null) ? $invoiceResponse['invoice'] : [];
        if ($invoice === []) {
            throw new \RuntimeException('Square invoice not found.');
        }

        return $this->buildInvoiceResponse($invoice, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function getInvoiceSummary(string $invoiceId): array
    {
        $normalizedInvoiceId = trim($invoiceId);
        if ($normalizedInvoiceId === '') {
            throw new \InvalidArgumentException('Invoice ID is required.');
        }

        $invoiceResponse = $this->request('GET', '/v2/invoices/' . $normalizedInvoiceId);
        $invoice = is_array($invoiceResponse['invoice'] ?? null) ? $invoiceResponse['invoice'] : [];
        if ($invoice === []) {
            throw new \RuntimeException('Square invoice not found.');
        }

        return [
            'invoice' => $invoice,
            'public_url' => $this->extractInvoicePublicUrl($invoice),
            'payment_status' => $this->detectInvoicePaymentStatus($invoice),
        ];
    }

    /**
     * Build invoice response with optional related data fetching.
     * @param array<string,mixed> $invoice
     * @param bool $fetchRelatedData Whether to fetch order, customer, and payment data
     * @return array<string,mixed>
     */
    private function buildInvoiceResponse(array $invoice, bool $fetchRelatedData = false): array
    {
        $order = null;
        $customer = null;
        $payments = [];
        $fetchErrors = [];

        if ($fetchRelatedData) {
            $orderId = trim((string) ($invoice['order_id'] ?? ''));
            $customerId = trim((string) ($invoice['primary_recipient']['customer_id'] ?? ''));

            if ($orderId !== '') {
                try {
                    $orderResponse = $this->request('GET', '/v2/orders/' . rawurlencode($orderId));
                    $order = is_array($orderResponse['order'] ?? null) ? $orderResponse['order'] : null;
                } catch (\Throwable $exception) {
                    $fetchErrors['order'] = $exception->getMessage();
                }
            }

            if ($customerId !== '') {
                try {
                    $customerResponse = $this->request('GET', '/v2/customers/' . rawurlencode($customerId));
                    $customer = is_array($customerResponse['customer'] ?? null) ? $customerResponse['customer'] : null;
                } catch (\Throwable $exception) {
                    $fetchErrors['customer'] = $exception->getMessage();
                }
            }

            if ($orderId !== '') {
                try {
                    $paymentsResponse = $this->request('POST', '/v2/payments/search', [
                        'query' => [
                            'filter' => [
                                'order_ids' => [$orderId],
                            ],
                        ],
                        'limit' => 100,
                    ]);

                    $payments = is_array($paymentsResponse['payments'] ?? null) ? $paymentsResponse['payments'] : [];
                } catch (\Throwable $exception) {
                    $fetchErrors['payments'] = $exception->getMessage();
                }
            }
        }

        $analysis = $this->buildInvoiceAnalysis($invoice, $order, $payments);

        $result = [
            'invoice' => $invoice,
            'public_url' => $this->extractInvoicePublicUrl($invoice),
            'analysis' => $analysis,
            'payment_status' => $this->detectInvoicePaymentStatus($invoice),
        ];

        if ($order !== null) {
            $result['order'] = $order;
        }

        if ($customer !== null) {
            $result['customer'] = $customer;
        }

        if ($payments !== []) {
            $result['payments'] = $payments;
        }

        if ($fetchErrors !== []) {
            $result['detail_fetch_errors'] = $fetchErrors;
        }

        return $result;
    }

    /**
     * @param array<int, array<string,mixed>> $invoices
     * @return array<int, array<string,mixed>>
     */
    private function filterInvoicesByPaymentStatus(array $invoices, ?string $paymentStatus): array
    {
        $normalized = strtolower(trim((string) $paymentStatus));
        if (!in_array($normalized, ['paid', 'unpaid'], true)) {
            return $invoices;
        }

        return array_values(array_filter($invoices, function ($invoice) use ($normalized): bool {
            if (!is_array($invoice)) {
                return false;
            }

            return $this->detectInvoicePaymentStatus($invoice) === $normalized;
        }));
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function detectInvoicePaymentStatus(array $invoice): string
    {
        $paymentRequests = is_array($invoice['payment_requests'] ?? null) ? $invoice['payment_requests'] : [];
        
        $totalRequested = 0;
        $totalCompleted = 0;
        
        foreach ($paymentRequests as $request) {
            if (!is_array($request)) {
                continue;
            }
            
            $totalRequested += $this->moneyAmount($request['computed_amount_money'] ?? null);
            $totalCompleted += $this->moneyAmount($request['total_completed_amount_money'] ?? null);
        }
        
        // If no payment requests, check legacy amount_money fields
        if ($totalRequested === 0) {
            $amount = $this->moneyAmount($invoice['amount_money'] ?? null);
            $paid = $this->moneyAmount($invoice['paid_amount_money'] ?? null);
            
            if ($amount > 0 && $paid >= $amount) {
                return 'paid';
            }
        } else if ($totalRequested > 0 && $totalCompleted >= $totalRequested) {
            return 'paid';
        }

        return 'unpaid';
    }

    private function searchCustomerByEmail(string $email): ?string
    {
        $response = $this->request('POST', '/v2/customers/search', [
            'query' => [
                'filter' => [
                    'email_address' => [
                        'exact' => $email,
                    ],
                ],
            ],
            'limit' => 1,
        ]);

        $customer = $response['customers'][0]['id'] ?? null;

        return is_string($customer) && $customer !== '' ? $customer : null;
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed>|null $order
     * @param array<int,array<string,mixed>> $payments
     * @return array<string,mixed>
     */
    private function buildInvoiceAnalysis(array $invoice, ?array $order, array $payments): array
    {
        $orderLineItems = is_array($order['line_items'] ?? null) ? $order['line_items'] : [];
        $lineItems = [];
        $lineItemsSubtotal = 0;
        $lineItemsDiscountTotal = 0;
        $lineItemsTaxTotal = 0;

        foreach ($orderLineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $lineTotal = $this->moneyAmount($lineItem['total_money'] ?? null);
            $gross = $this->moneyAmount($lineItem['gross_sales_money'] ?? null);
            $discount = $this->moneyAmount($lineItem['total_discount_money'] ?? null);
            $tax = $this->moneyAmount($lineItem['total_tax_money'] ?? null);

            $lineItemsSubtotal += $lineTotal;
            $lineItemsDiscountTotal += $discount;
            $lineItemsTaxTotal += $tax;

            $lineItems[] = [
                'uid' => $lineItem['uid'] ?? null,
                'name' => $lineItem['name'] ?? null,
                'variation_name' => $lineItem['variation_name'] ?? null,
                'quantity' => $lineItem['quantity'] ?? null,
                'note' => $lineItem['note'] ?? null,
                'catalog_object_id' => $lineItem['catalog_object_id'] ?? null,
                'catalog_version' => $lineItem['catalog_version'] ?? null,
                'base_price_money' => $this->normalizeMoney($lineItem['base_price_money'] ?? null),
                'gross_sales_money' => $this->normalizeMoney($lineItem['gross_sales_money'] ?? null),
                'total_discount_money' => $this->normalizeMoney($lineItem['total_discount_money'] ?? null),
                'total_tax_money' => $this->normalizeMoney($lineItem['total_tax_money'] ?? null),
                'total_money' => $this->normalizeMoney($lineItem['total_money'] ?? null),
                'modifiers' => is_array($lineItem['modifiers'] ?? null) ? $lineItem['modifiers'] : [],
                'pricing_blocklists' => is_array($lineItem['pricing_blocklists'] ?? null) ? $lineItem['pricing_blocklists'] : [],
                'applied_discounts' => is_array($lineItem['applied_discounts'] ?? null) ? $lineItem['applied_discounts'] : [],
                'applied_taxes' => is_array($lineItem['applied_taxes'] ?? null) ? $lineItem['applied_taxes'] : [],
                'computed' => [
                    'line_total_amount' => $lineTotal,
                    'gross_amount' => $gross,
                    'discount_amount' => $discount,
                    'tax_amount' => $tax,
                ],
            ];
        }

        $paymentRequests = is_array($invoice['payment_requests'] ?? null) ? $invoice['payment_requests'] : [];
        $paymentRequestSummary = [];
        $requestedByScheduleTotal = 0;
        $paidByScheduleTotal = 0;
        $balanceByScheduleTotal = 0;

        foreach ($paymentRequests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $requestAmount = $this->moneyAmount($request['computed_amount_money'] ?? null);
            $paidAmount = $this->moneyAmount($request['total_completed_amount_money'] ?? null);
            $balanceAmount = max(0, $requestAmount - $paidAmount);

            $requestedByScheduleTotal += $requestAmount;
            $paidByScheduleTotal += $paidAmount;
            $balanceByScheduleTotal += $balanceAmount;

            $paymentRequestSummary[] = [
                'uid' => $request['uid'] ?? null,
                'request_type' => $request['request_type'] ?? null,
                'due_date' => $request['due_date'] ?? null,
                'tipping_enabled' => $request['tipping_enabled'] ?? null,
                'computed_amount_money' => $this->normalizeMoney($request['computed_amount_money'] ?? null),
                'total_completed_amount_money' => $this->normalizeMoney($request['total_completed_amount_money'] ?? null),
                'status' => $request['status'] ?? null,
                'automatic_payment_source' => $request['automatic_payment_source'] ?? null,
                'computed' => [
                    'requested_amount' => $requestAmount,
                    'paid_amount' => $paidAmount,
                    'balance_amount' => $balanceAmount,
                ],
            ];
        }

        $capturedPaymentsTotal = 0;
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $capturedPaymentsTotal += $this->moneyAmount($payment['amount_money'] ?? null);
        }

        return [
            'invoice_id' => $invoice['id'] ?? null,
            'public_url' => $this->extractInvoicePublicUrl($invoice),
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'status' => $invoice['status'] ?? null,
            'title' => $invoice['title'] ?? null,
            'description' => $invoice['description'] ?? null,
            'delivery_method' => $invoice['delivery_method'] ?? null,
            'currency' => $this->resolveCurrencyCode($invoice, $order),
            'timeline' => [
                'created_at' => $invoice['created_at'] ?? null,
                'updated_at' => $invoice['updated_at'] ?? null,
                'scheduled_at' => $invoice['scheduled_at'] ?? null,
                'next_payment_amount_date' => $invoice['next_payment_amount_money_date'] ?? null,
                'accepted_at' => $invoice['accepted_at'] ?? null,
                'payment_requests' => $paymentRequestSummary,
            ],
            'totals' => [
                'line_items_subtotal_amount' => $lineItemsSubtotal,
                'line_items_discount_amount' => $lineItemsDiscountTotal,
                'line_items_tax_amount' => $lineItemsTaxTotal,
                'scheduled_requested_amount' => $requestedByScheduleTotal,
                'scheduled_paid_amount' => $paidByScheduleTotal,
                'scheduled_balance_amount' => $balanceByScheduleTotal,
                'captured_payments_amount' => $capturedPaymentsTotal,
            ],
            'line_item_count' => count($lineItems),
            'payment_count' => count($payments),
            'line_items' => $lineItems,
        ];
    }

    /**
     * @param mixed $money
     * @return array{amount:int,currency:string}|null
     */
    private function normalizeMoney($money): ?array
    {
        if (!is_array($money)) {
            return null;
        }

        return [
            'amount' => $this->moneyAmount($money),
            'currency' => strtoupper(trim((string) ($money['currency'] ?? $this->config->currency))),
        ];
    }

    /**
     * @param mixed $money
     */
    private function moneyAmount($money): int
    {
        if (!is_array($money)) {
            return 0;
        }

        return (int) ($money['amount'] ?? 0);
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<string,mixed>|null $order
     */
    private function resolveCurrencyCode(array $invoice, ?array $order): string
    {
        $candidate = '';

        if (is_array($invoice['amount_money'] ?? null)) {
            $candidate = trim((string) ($invoice['amount_money']['currency'] ?? ''));
        }

        if ($candidate === '' && is_array($order) && is_array($order['total_money'] ?? null)) {
            $candidate = trim((string) ($order['total_money']['currency'] ?? ''));
        }

        if ($candidate === '') {
            $candidate = trim((string) $this->config->currency);
        }

        return strtoupper($candidate);
    }

    /**
     * @param array<string,mixed> $projectData
     */
    private function calculateProjectLineAmount(array $projectData, ?int $estimatedAmountCents): int
    {
        $baseAmount = max(0, (int) ($estimatedAmountCents ?? 0));
        $paymentType = strtolower(trim((string) ($projectData['payment_type'] ?? 'fixed_rate')));

        if ($paymentType !== 'hourly') {
            return $baseAmount;
        }

        $hours = $this->toFloat($projectData['hourly_hours'] ?? 0);
        if ($hours <= 0) {
            return $baseAmount;
        }

        return max(0, (int) round($baseAmount * $hours));
    }

    /**
     * @param array<string,mixed> $projectData
     */
    private function buildPricingSummary(array $projectData, ?int $estimatedAmountCents): ?string
    {
        $paymentType = strtolower(trim((string) ($projectData['payment_type'] ?? 'fixed_rate')));
        $baseAmount = max(0, (int) ($estimatedAmountCents ?? 0));

        if ($paymentType === 'hourly') {
            $hours = $this->toFloat($projectData['hourly_hours'] ?? 0);
            if ($hours <= 0) {
                return 'payment_type: hourly';
            }

            $totalAmount = $this->calculateProjectLineAmount($projectData, $estimatedAmountCents);

            return 'payment_type: hourly | hours: ' . number_format($hours, 2, '.', '') . ' | rate: ' . $this->formatMoneyAmount($baseAmount) . '/hr | total: ' . $this->formatMoneyAmount($totalAmount);
        }

        return 'payment_type: fixed_rate | total: ' . $this->formatMoneyAmount($baseAmount);
    }

    private function formatMoneyAmount(int $amount): string
    {
        return '$' . number_format($amount / 100, 2, '.', ',');
    }

    /**
     * @param mixed $value
     */
    private function toFloat($value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0.0;
            }

            return (float) $trimmed;
        }

        return 0.0;
    }

    /**
     * Extract or construct public URL from Square invoice object.
     * @param array<string,mixed> $invoice
     */
    private function extractInvoicePublicUrl(array $invoice): ?string
    {
        // First try to get the public_url from the invoice object
        $publicUrl = trim((string) ($invoice['public_url'] ?? ''));
        if ($publicUrl !== '') {
            return $publicUrl;
        }

        // If not present, construct the URL based on environment
        $invoiceId = trim((string) ($invoice['id'] ?? ''));
        if ($invoiceId === '') {
            return null;
        }

        // Determine if sandbox or production based on baseUrl
        $baseUrl = rtrim($this->config->baseUrl, '/');
        if (strpos($baseUrl, 'sandbox') !== false) {
            return 'https://squareupsandbox.com/i/' . $invoiceId;
        }

        return 'https://square.com/i/' . $invoiceId;
    }

    /**
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        if (!$this->isConfigured()) {
            log_message('error', 'Square not configured. Missing token or location. base_url={baseUrl}, api_version={apiVersion}', [
                'baseUrl' => rtrim($this->config->baseUrl, '/'),
                'apiVersion' => $this->config->apiVersion,
            ]);
            throw new \RuntimeException('Square is not configured. Set access token and location ID.');
        }

        $client = service('curlrequest', [
            'baseURI' => rtrim($this->config->baseUrl, '/'),
            'timeout' => 25,
            'http_errors' => false,
        ]);

        $requestOptions = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->accessToken,
                'Content-Type' => 'application/json',
                'Square-Version' => $this->config->apiVersion,
            ],
        ];

        if (strtoupper($method) !== 'GET' || $payload !== []) {
            $requestOptions['json'] = $payload;
        }

        try {
            $response = $client->request($method, ltrim($path, '/'), $requestOptions);
        } catch (\Throwable $exception) {
            log_message('error', 'Square request transport failure. method={method}, path={path}, base_url={baseUrl}, api_version={apiVersion}, location_id={locationId}, error={error}', [
                'method' => strtoupper($method),
                'path' => '/' . ltrim($path, '/'),
                'baseUrl' => rtrim($this->config->baseUrl, '/'),
                'apiVersion' => $this->config->apiVersion,
                'locationId' => $this->config->locationId,
                'error' => $exception->getMessage(),
                'requestPayload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            throw $exception;
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 400) {
            $error = $decoded['errors'][0]['detail'] ?? $raw;
            log_message('error', 'Square API failure. method={method}, path={path}, status={status}, base_url={baseUrl}, api_version={apiVersion}, location_id={locationId}, response={response}, request_payload={requestPayload}', [
                'method' => strtoupper($method),
                'path' => '/' . ltrim($path, '/'),
                'status' => $status,
                'baseUrl' => rtrim($this->config->baseUrl, '/'),
                'apiVersion' => $this->config->apiVersion,
                'locationId' => $this->config->locationId,
                'response' => $raw,
                'requestPayload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            throw new \RuntimeException('Square API error: ' . $error);
        }

        return $decoded;
    }

    private function idempotencyKey(string $type, int $projectId): string
    {
        return $type . '-' . $projectId . '-' . bin2hex(random_bytes(8));
    }

    /**
     * @param mixed $value
     */
    private function formatProjectField(string $key, $value): ?string
    {
        $fieldName = $key;

        if ($value === null) {
            return $fieldName . ': null';
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if ($fieldName === 'services') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $services = [];
                    foreach ($decoded as $service) {
                        $serviceValue = trim((string) $service);
                        if ($serviceValue !== '') {
                            $services[] = $serviceValue;
                        }
                    }

                    return $fieldName . ': ' . ($services === [] ? '[]' : implode(', ', $services));
                }
            }

            return $fieldName . ': ' . $trimmed;
        }

        if (is_array($value)) {
            if ($value === []) {
                return $fieldName . ': []';
            }

            return $fieldName . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $fieldName . ': ' . (string) $value;
    }
}
