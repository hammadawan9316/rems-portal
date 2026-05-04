<?php

namespace App\Controllers\Api;

use App\Libraries\SquareProjectQueueService;
use App\Libraries\SquareService;
use App\Models\BusinessProfileModel;
use App\Models\ProjectModel;
use App\Models\QuotationModel;

class InvoiceController extends BaseApiController
{
    public function createSquareInvoice(int $id)
    {
        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();
        $squareQueue = new SquareProjectQueueService();
        $square = new SquareService();

        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        if (!$square->isConfigured()) {
            return $this->res->badRequest('Square integration is not configured.');
        }

        $projects = $projectModel
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        if ($projects === []) {
            return $this->res->badRequest('Cannot create Square invoice for a quotation without projects.');
        }

        $existingInvoiceId = trim((string) ($quotation['square_invoice_id'] ?? ''));
        if ($existingInvoiceId !== '') {
            return $this->res->badRequest('Square invoice is already linked to this quotation.', [
                'square_invoice_id' => $existingInvoiceId,
            ]);
        }

        $jobId = $squareQueue->enqueue($id);

        return $this->res->ok([
            'quotation_id' => $id,
            'queue_job_id' => $jobId,
            'square_processing' => 'queued_for_cron',
        ], 'Square invoice creation has been queued.');
    }

    public function squareInvoices()
    {
        $square = new SquareService();
        if (!$square->isConfigured()) {
            return $this->res->badRequest('Square integration is not configured.');
        }

        $params = $this->getListQueryParams(10, 100);
        $cursor = trim((string) ($this->request->getGet('next_cursor') ?? ''));
        $paymentStatusResult = $this->resolvePaymentStatusFilter($this->request->getGet('payment_status'));
        if (is_array($paymentStatusResult) && isset($paymentStatusResult['error'])) {
            return $this->res->badRequest('Invalid payment status filter.', [
                'payment_status' => (string) $paymentStatusResult['error'],
            ]);
        }
        $paymentStatus = is_string($paymentStatusResult) ? $paymentStatusResult : null;

        try {
            $result = $square->listInvoices($params['perPage'], $cursor !== '' ? $cursor : null, $paymentStatus);
            $invoices = is_array($result['invoices'] ?? null) ? $result['invoices'] : [];
            $invoiceToQuotation = $this->mapSquareInvoicesToQuotations($invoices);
            $items = [];

            foreach ($invoices as $invoice) {
                if (!is_array($invoice)) {
                    continue;
                }

                $invoiceId = trim((string) ($invoice['id'] ?? ''));
                $base = [
                    'invoice' => $invoice,
                    'payment_status' => $this->detectPaymentStatusFromInvoice($invoice),
                    'linked_quotation' => $invoiceToQuotation[$invoiceId] ?? null,
                ];

                $items[] = $base;
            }

            return $this->res->ok([
                'items' => $items,
                'pagination' => [
                    'count' => count($items),
                    'per_page' => $params['perPage'],
                    'page' => $params['page'],
                    'next_cursor' => $result['cursor'] ?? null,
                ],
                'meta' => [
                    'include_details' => false,
                    'payment_status_filter' => $paymentStatus,
                    'square_api_version' => $square->getApiVersion(),
                ],
            ], 'Square invoices retrieved successfully.');
        } catch (\Throwable $exception) {
            return $this->res->serverError('Unable to retrieve Square invoices: ' . $exception->getMessage());
        }
    }

    public function squareInvoice(string $invoiceId)
    {
        $square = new SquareService();
        if (!$square->isConfigured()) {
            return $this->res->badRequest('Square integration is not configured.');
        }

        $normalizedInvoiceId = trim($invoiceId);
        if ($normalizedInvoiceId === '') {
            return $this->res->badRequest('Square invoice ID is required.');
        }

        try {
            $detail = $square->getInvoiceWithAnalysis($normalizedInvoiceId);
            $mapped = $this->mapSquareInvoicesToQuotations([
                is_array($detail['invoice'] ?? null) ? $detail['invoice'] : ['id' => $normalizedInvoiceId],
            ]);
            $detail['linked_quotation'] = $mapped[$normalizedInvoiceId] ?? null;
            $detail['business_profile'] = (new BusinessProfileModel())->findActive() ?: null;

            return $this->res->ok($detail, 'Square invoice retrieved successfully.');
        } catch (\Throwable $exception) {
            return $this->res->serverError('Unable to retrieve Square invoice: ' . $exception->getMessage());
        }
    }

    public function squareInvoiceByQuotation(int $id)
    {
        $quotationModel = new QuotationModel();
        $square = new SquareService();

        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        if (!$square->isConfigured()) {
            return $this->res->badRequest('Square integration is not configured.');
        }

        $invoiceId = trim((string) ($quotation['square_invoice_id'] ?? ''));
        if ($invoiceId === '') {
            return $this->res->notFound('No Square invoice is linked to this quotation.');
        }

        try {
            $detail = $square->getInvoiceSummary($invoiceId);
            $detail['linked_quotation'] = [
                'id' => (int) ($quotation['id'] ?? 0),
                'quote_number' => $this->normalizeNullableText($quotation['quote_number'] ?? null),
                'status' => $this->normalizeNullableText($quotation['status'] ?? null),
                'square_invoice_id' => $invoiceId,
            ];

            return $this->res->ok($detail, 'Square invoice retrieved successfully.');
        } catch (\Throwable $exception) {
            return $this->res->serverError('Unable to retrieve Square invoice: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<int, array<string,mixed>> $invoices
     * @return array<string, array{id:int,quote_number:?string,status:?string,square_invoice_id:?string,square_order_id:?string,project_id:?int}>
     */
    private function mapSquareInvoicesToQuotations(array $invoices): array
    {
        $cleanInvoices = array_values(array_filter($invoices, static fn ($invoice): bool => is_array($invoice)));
        if ($cleanInvoices === []) {
            return [];
        }

        $invoiceIds = [];
        $orderIds = [];
        foreach ($cleanInvoices as $invoice) {
            $invoiceId = trim((string) ($invoice['id'] ?? ''));
            if ($invoiceId !== '') {
                $invoiceIds[] = $invoiceId;
            }

            $orderId = trim((string) ($invoice['order_id'] ?? ''));
            if ($orderId !== '') {
                $orderIds[] = $orderId;
            }
        }

        $invoiceIds = array_values(array_unique($invoiceIds));
        $orderIds = array_values(array_unique($orderIds));

        if ($invoiceIds === [] && $orderIds === []) {
            return [];
        }

        $quotationBuilder = model(QuotationModel::class)
            ->select('id, quote_number, status, square_invoice_id, square_order_id');

        if ($invoiceIds !== [] && $orderIds !== []) {
            $quotationBuilder
                ->groupStart()
                ->whereIn('square_invoice_id', $invoiceIds)
                ->orWhereIn('square_order_id', $orderIds)
                ->groupEnd();
        } elseif ($invoiceIds !== []) {
            $quotationBuilder->whereIn('square_invoice_id', $invoiceIds);
        } else {
            $quotationBuilder->whereIn('square_order_id', $orderIds);
        }

        $rows = $quotationBuilder
            ->findAll();

        $mappedByInvoiceId = [];
        $mappedByOrderId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $summary = [
                'id' => (int) ($row['id'] ?? 0),
                'quote_number' => $this->normalizeNullableText($row['quote_number'] ?? null),
                'status' => $this->normalizeNullableText($row['status'] ?? null),
                'square_invoice_id' => $this->normalizeNullableText($row['square_invoice_id'] ?? null),
                'square_order_id' => $this->normalizeNullableText($row['square_order_id'] ?? null),
                'project_id' => null,
            ];

            $invoiceId = trim((string) ($row['square_invoice_id'] ?? ''));
            if ($invoiceId !== '' && !isset($mappedByInvoiceId[$invoiceId])) {
                $mappedByInvoiceId[$invoiceId] = $summary;
            }

            $orderId = trim((string) ($row['square_order_id'] ?? ''));
            if ($orderId !== '' && !isset($mappedByOrderId[$orderId])) {
                $mappedByOrderId[$orderId] = $summary;
            }
        }

        $mapped = [];
        foreach ($cleanInvoices as $invoice) {
            $invoiceId = trim((string) ($invoice['id'] ?? ''));
            if ($invoiceId === '' || isset($mapped[$invoiceId])) {
                continue;
            }

            $orderId = trim((string) ($invoice['order_id'] ?? ''));

            if ($invoiceId !== '' && isset($mappedByInvoiceId[$invoiceId])) {
                $mapped[$invoiceId] = $mappedByInvoiceId[$invoiceId];
                continue;
            }

            if ($orderId !== '' && isset($mappedByOrderId[$orderId])) {
                $mapped[$invoiceId] = $mappedByOrderId[$orderId];
            }
        }

        // Fallback: extract quotation ID embedded in invoice text and map directly.
        $missingByText = [];
        foreach ($cleanInvoices as $invoice) {
            $invoiceId = trim((string) ($invoice['id'] ?? ''));
            if ($invoiceId === '' || isset($mapped[$invoiceId])) {
                continue;
            }

            $quotationId = $this->extractQuotationIdFromInvoice($invoice);
            if ($quotationId > 0) {
                $missingByText[$invoiceId] = $quotationId;
            }
        }

        if ($missingByText !== []) {
            $quotationIds = array_values(array_unique(array_values($missingByText)));
            $fallbackRows = model(QuotationModel::class)
                ->select('id, quote_number, status, square_invoice_id, square_order_id')
                ->whereIn('id', $quotationIds)
                ->findAll();

            $byQuotationId = [];
            foreach ($fallbackRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $quotationId = (int) ($row['id'] ?? 0);
                if ($quotationId < 1) {
                    continue;
                }

                $byQuotationId[$quotationId] = [
                    'id' => $quotationId,
                    'quote_number' => $this->normalizeNullableText($row['quote_number'] ?? null),
                    'status' => $this->normalizeNullableText($row['status'] ?? null),
                    'square_invoice_id' => $this->normalizeNullableText($row['square_invoice_id'] ?? null),
                    'square_order_id' => $this->normalizeNullableText($row['square_order_id'] ?? null),
                    'project_id' => null,
                ];
            }

            foreach ($missingByText as $invoiceId => $quotationId) {
                if (!isset($mapped[$invoiceId]) && isset($byQuotationId[$quotationId])) {
                    $mapped[$invoiceId] = $byQuotationId[$quotationId];
                }
            }
        }

        return $mapped;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function extractQuotationIdFromInvoice(array $invoice): int
    {
        $candidates = [
            trim((string) ($invoice['description'] ?? '')),
            trim((string) ($invoice['title'] ?? '')),
        ];

        foreach ($candidates as $text) {
            if ($text === '') {
                continue;
            }

            if (preg_match('/quotation_id\s*[:=]\s*(\d+)/i', $text, $match) === 1) {
                return (int) ($match[1] ?? 0);
            }

            if (preg_match('/quotation\s*#\s*(\d+)/i', $text, $match) === 1) {
                return (int) ($match[1] ?? 0);
            }
        }

        return 0;
    }

    private function parseBooleanParam(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return $default;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * @return string|array{error:string}|null
     */
    private function resolvePaymentStatusFilter(mixed $paymentStatus)
    {
        if ($paymentStatus === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $paymentStatus));
        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, ['paid', 'unpaid'], true)) {
            return [
                'error' => 'Allowed values: paid, unpaid.',
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function detectPaymentStatusFromInvoice(array $invoice): string
    {
        $paymentRequests = is_array($invoice['payment_requests'] ?? null) ? $invoice['payment_requests'] : [];

        $totalRequested = 0;
        $totalCompleted = 0;

        foreach ($paymentRequests as $request) {
            if (!is_array($request)) {
                continue;
            }

            $computed = is_array($request['computed_amount_money'] ?? null) ? (int) ($request['computed_amount_money']['amount'] ?? 0) : 0;
            $completed = is_array($request['total_completed_amount_money'] ?? null) ? (int) ($request['total_completed_amount_money']['amount'] ?? 0) : 0;

            $totalRequested += $computed;
            $totalCompleted += $completed;
        }

        if ($totalRequested === 0) {
            $amount = is_array($invoice['amount_money'] ?? null) ? (int) ($invoice['amount_money']['amount'] ?? 0) : 0;
            $paid = is_array($invoice['paid_amount_money'] ?? null) ? (int) ($invoice['paid_amount_money']['amount'] ?? 0) : 0;

            if ($amount > 0 && $paid >= $amount) {
                return 'paid';
            }
        } elseif ($totalCompleted >= $totalRequested) {
            return 'paid';
        }

        return 'unpaid';
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        return $text === '' ? null : $text;
    }
}