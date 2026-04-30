<?php

namespace App\Controllers\Api;

use App\Libraries\SquareProjectQueueService;
use App\Libraries\SquareService;
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
            $invoiceIds = [];

            foreach ($invoices as $invoice) {
                if (!is_array($invoice)) {
                    continue;
                }

                $invoiceId = trim((string) ($invoice['id'] ?? ''));
                if ($invoiceId !== '') {
                    $invoiceIds[] = $invoiceId;
                }
            }

            $invoiceToQuotation = $this->mapSquareInvoicesToQuotations($invoiceIds);
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
            $detail = $square->getInvoiceSummary($normalizedInvoiceId);
            $mapped = $this->mapSquareInvoicesToQuotations([$normalizedInvoiceId]);
            $detail['linked_quotation'] = $mapped[$normalizedInvoiceId] ?? null;

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
     * @param array<int, string> $invoiceIds
     * @return array<string, array{id:int,quote_number:?string,status:?string,square_invoice_id:string}>
     */
    private function mapSquareInvoicesToQuotations(array $invoiceIds): array
    {
        $cleanInvoiceIds = array_values(array_unique(array_filter(array_map(static fn (string $id): string => trim($id), $invoiceIds), static fn (string $id): bool => $id !== '')));
        if ($cleanInvoiceIds === []) {
            return [];
        }

        $rows = model(QuotationModel::class)
            ->select('id, quote_number, status, square_invoice_id')
            ->whereIn('square_invoice_id', $cleanInvoiceIds)
            ->findAll();

        $mapped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $invoiceId = trim((string) ($row['square_invoice_id'] ?? ''));
            if ($invoiceId === '' || isset($mapped[$invoiceId])) {
                continue;
            }

            $mapped[$invoiceId] = [
                'id' => (int) ($row['id'] ?? 0),
                'quote_number' => $this->normalizeNullableText($row['quote_number'] ?? null),
                'status' => $this->normalizeNullableText($row['status'] ?? null),
                'square_invoice_id' => $invoiceId,
            ];
        }

        return $mapped;
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