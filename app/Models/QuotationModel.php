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
        'quote_number',
        'title',
        'status',
        'notes',
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
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateQuotations(?int $customerId = null, string $search = '', int $perPage = 20, int $offset = 0): array
    {
        $search = trim($search);

        $countBuilder = $this->builder()
            ->select('COUNT(DISTINCT quotations.id) AS total', false)
            ->join('customers', 'customers.id = quotations.customer_id', 'left');

        if ($customerId !== null && $customerId > 0) {
            $countBuilder->where('quotations.customer_id', $customerId);
        }

        if ($search !== '') {
            $countBuilder->groupStart()
                ->like('quotations.quote_number', $search)
                ->orLike('quotations.title', $search)
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
            ->select('quotations.*, customers.name AS customer_name, customers.email AS customer_email, customers.company AS customer_company')
            ->join('customers', 'customers.id = quotations.customer_id', 'left');

        if ($customerId !== null && $customerId > 0) {
            $itemsBuilder->where('quotations.customer_id', $customerId);
        }

        if ($search !== '') {
            $itemsBuilder->groupStart()
                ->like('quotations.quote_number', $search)
                ->orLike('quotations.title', $search)
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
