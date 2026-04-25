<?php

namespace App\Models;

use CodeIgniter\Model;

class QuotationModel extends Model
{
    protected $table = 'quotations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'customer_id',
        'source_request_id',
        'quote_number',
        'description',
        'status',
        'notes',
        'public_response_token_hash',
        'public_response_token_issued_at',
        'public_response_token_expires_at',
        'public_response_token_used_at',
        'response_reason',
        'response_actor',
        'response_at',
        'discount_type',
        'discount_value',
        'discount_scope',
        'square_order_id',
        'square_invoice_id',
        'square_status',
        'square_error',
        'square_synced_at',
        'submitted_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function generateQuoteNumber(): string
    {
        return 'QT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * @return array{token:string,issued_at:string,expires_at:string}|null
     */
    public function issuePublicResponseToken(int $quotationId, int $expiryDays = 7): ?array
    {
        if ($quotationId < 1) {
            return null;
        }

        $quotation = $this->find($quotationId);
        if (!is_array($quotation)) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $issuedAt = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . max(1, $expiryDays) . ' days'));

        $updated = $this->update($quotationId, [
            'public_response_token_hash' => hash('sha256', $token),
            'public_response_token_issued_at' => $issuedAt,
            'public_response_token_expires_at' => $expiresAt,
            'public_response_token_used_at' => null,
        ]);

        if (!$updated) {
            return null;
        }

        return [
            'token' => $token,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
    }

    public function findActiveByPublicResponseToken(string $token): ?array
    {
        $plainToken = $this->normalizePublicResponseToken($token);
        if ($plainToken === '') {
            return null;
        }

        $quotation = $this->where('public_response_token_hash', hash('sha256', $plainToken))
            ->where('public_response_token_used_at', null)
            ->where('public_response_token_expires_at >', date('Y-m-d H:i:s'))
            ->first();

        return is_array($quotation) ? $quotation : null;
    }

    public function findByPublicResponseToken(string $token): ?array
    {
        $plainToken = $this->normalizePublicResponseToken($token);
        if ($plainToken === '') {
            return null;
        }

        $quotation = $this->where('public_response_token_hash', hash('sha256', $plainToken))
            ->first();

        return is_array($quotation) ? $quotation : null;
    }

    public function invalidatePublicResponseToken(int $quotationId): bool
    {
        if ($quotationId < 1) {
            return false;
        }

        return $this->update($quotationId, [
            'public_response_token_used_at' => date('Y-m-d H:i:s'),
            'public_response_token_expires_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function normalizePublicResponseToken(string $token): string
    {
        $normalized = trim(rawurldecode($token));
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'");

        // Support accidental full URL/query input by extracting token=... when present.
        if (str_contains($normalized, 'token=')) {
            $query = parse_url($normalized, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                $candidate = trim((string) ($params['token'] ?? ''));
                if ($candidate !== '') {
                    $normalized = $candidate;
                }
            } else {
                parse_str($normalized, $params);
                $candidate = trim((string) ($params['token'] ?? ''));
                if ($candidate !== '') {
                    $normalized = $candidate;
                }
            }
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $normalized) === 1) {
            return strtolower($normalized);
        }

        return $normalized;
    }

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateQuotations(?int $customerId = null, string $search = '', int $perPage = 20, int $offset = 0, ?string $status = null): array
    {
        $search = trim($search);
        $status = trim((string) $status);

        $countBuilder = $this->builder()
            ->select('COUNT(DISTINCT quotations.id) AS total', false)
            ->join('customers', 'customers.id = quotations.customer_id', 'left');

        if ($customerId !== null && $customerId > 0) {
            $countBuilder->where('quotations.customer_id', $customerId);
        }

        if ($status !== '') {
            $countBuilder->where('quotations.status', $status);
        }

        if ($search !== '') {
            $countBuilder->groupStart()
                ->like('quotations.quote_number', $search)
                ->orLike('quotations.description', $search)
                ->orLike('quotations.status', $search)
                ->orLike('quotations.notes', $search)
                ->orLike('customers.name', $search)
                ->orLike('customers.email', $search)
                ->orLike('customers.company', $search)
                ->groupEnd();
        }

        $totalRow = $countBuilder->get()->getRowArray();
        $total = (int) ($totalRow['total'] ?? 0);

        $itemsBuilder = $this->builder()
            ->select('quotations.*, customers.id AS customer_ref_id, customers.name AS customer_name, customers.email AS customer_email, customers.phone AS customer_phone, customers.company AS customer_company')
            ->join('customers', 'customers.id = quotations.customer_id', 'left');

        if ($customerId !== null && $customerId > 0) {
            $itemsBuilder->where('quotations.customer_id', $customerId);
        }

        if ($status !== '') {
            $itemsBuilder->where('quotations.status', $status);
        }

        if ($search !== '') {
            $itemsBuilder->groupStart()
                ->like('quotations.quote_number', $search)
                ->orLike('quotations.description', $search)
                ->orLike('quotations.status', $search)
                ->orLike('quotations.notes', $search)
                ->orLike('customers.name', $search)
                ->orLike('customers.email', $search)
                ->orLike('customers.company', $search)
                ->groupEnd();
        }

        $quotations = $itemsBuilder
            ->orderBy('quotations.id', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return [
            'items' => $this->attachProjectCounts($quotations),
            'total' => $total,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $quotations
     * @return array<int, array<string, mixed>>
     */
    public function attachProjectCounts(array $quotations): array
    {
        if ($quotations === []) {
            return [];
        }

        $ids = array_map(static fn (array $quotation): int => (int) ($quotation['id'] ?? 0), $quotations);
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return $quotations;
        }

        $projectRows = model(ProjectModel::class)
            ->select('quotation_id, COUNT(*) AS total')
            ->whereIn('quotation_id', $ids)
            ->groupBy('quotation_id')
            ->findAll();

        $projectCountMap = [];
        foreach ($projectRows as $row) {
            $projectCountMap[(int) ($row['quotation_id'] ?? 0)] = (int) ($row['total'] ?? 0);
        }

        foreach ($quotations as &$quotation) {
            $qid = (int) ($quotation['id'] ?? 0);
            $quotation['project_count'] = $projectCountMap[$qid] ?? 0;
        }
        unset($quotation);

        return $quotations;
    }
}
