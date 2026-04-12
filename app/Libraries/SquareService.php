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
        $noteLines = ["Project #{$projectId}: {$description}"];

        $details = [
            'project_title' => $projectTitle,
            'project_description' => $description,
            'nature' => $projectData['nature'] ?? null,
            'trades' => $projectData['trades'] ?? null,
            'scope' => $projectData['scope'] ?? null,
            'estimate_type' => $projectData['estimate_type'] ?? null,
            'plans_url' => $projectData['plans_url'] ?? null,
            'zip_code' => $projectData['zip_code'] ?? null,
            'deadline' => $projectData['deadline'] ?? null,
            'deadline_date' => $projectData['deadline_date'] ?? null,
            'estimated_amount' => $estimatedAmountCents,
        ];

        $noteLines[] = '';
        $noteLines[] = 'Project Details:';
        foreach ($details as $key => $value) {
            $formatted = $this->formatProjectField($key, $value);
            if ($formatted !== null) {
                $noteLines[] = '- ' . $formatted;
            }
        }

        if ($fileLinks !== []) {
            $noteLines[] = '';
            $noteLines[] = 'File Links:';
            foreach ($fileLinks as $link) {
                $candidate = trim((string) $link);
                if ($candidate !== '') {
                    $noteLines[] = '- ' . $candidate;
                }
            }
        }

        $note = implode("\n", $noteLines);

        $lineAmount = $estimatedAmountCents === null ? 0 : max(0, $estimatedAmountCents);

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

        try {
            $response = $client->request($method, ltrim($path, '/'), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config->accessToken,
                    'Content-Type' => 'application/json',
                    'Square-Version' => $this->config->apiVersion,
                ],
                'json' => $payload,
            ]);
        } catch (\Throwable $exception) {
            log_message('error', 'Square request transport failure. method={method}, path={path}, base_url={baseUrl}, api_version={apiVersion}, location_id={locationId}, error={error}', [
                'method' => strtoupper($method),
                'path' => '/' . ltrim($path, '/'),
                'baseUrl' => rtrim($this->config->baseUrl, '/'),
                'apiVersion' => $this->config->apiVersion,
                'locationId' => $this->config->locationId,
                'error' => $exception->getMessage(),
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
            log_message('error', 'Square API failure. method={method}, path={path}, status={status}, base_url={baseUrl}, api_version={apiVersion}, location_id={locationId}, response={response}', [
                'method' => strtoupper($method),
                'path' => '/' . ltrim($path, '/'),
                'status' => $status,
                'baseUrl' => rtrim($this->config->baseUrl, '/'),
                'apiVersion' => $this->config->apiVersion,
                'locationId' => $this->config->locationId,
                'response' => $raw,
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
        if ($value === null) {
            return $key . ': null';
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if ($key === 'trades') {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $trades = [];
                    foreach ($decoded as $trade) {
                        $tradeValue = trim((string) $trade);
                        if ($tradeValue !== '') {
                            $trades[] = $tradeValue;
                        }
                    }

                    return $key . ': ' . ($trades === [] ? '[]' : implode(', ', $trades));
                }
            }

            return $key . ': ' . $trimmed;
        }

        if (is_array($value)) {
            if ($value === []) {
                return $key . ': []';
            }

            return $key . ': ' . json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return $key . ': ' . (string) $value;
    }
}
