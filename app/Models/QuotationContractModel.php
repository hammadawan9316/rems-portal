<?php

namespace App\Models;

use CodeIgniter\Model;

class QuotationContractModel extends Model
{
    protected $table = 'quotation_contracts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'quotation_id',
        'contract_id',
        'owner_name',
        'owner_signature',
        'recipient_name',
        'recipient_signature',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * @return array<string, mixed>|null
     */
    public function findByQuotationId(int $quotationId): ?array
    {
        $row = $this->where('quotation_id', $quotationId)
            ->orderBy('id', 'DESC')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, int> $clauseIds
     * @return array<string, mixed>|null
     */
    public function saveAssignmentWithClauses(array $payload, array $clauseIds): ?array
    {
        $db = $this->db;
        $db->transStart();

        $existing = $this->findByQuotationId((int) ($payload['quotation_id'] ?? 0));
        if (is_array($existing)) {
            $this->update((int) ($existing['id'] ?? 0), $payload);
        } else {
            $this->insert($payload);
        }

        $saved = $this->findByQuotationId((int) ($payload['quotation_id'] ?? 0));
        if (!is_array($saved)) {
            $db->transRollback();

            return null;
        }

        $this->syncAssignedClauses((int) ($saved['id'] ?? 0), $clauseIds);

        $db->transComplete();
        if (!$db->transStatus()) {
            return null;
        }

        return $saved;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolveAssignedClauses(int $quotationContractId): array
    {
        if ($quotationContractId < 1) {
            return [];
        }

        return $this->db->table('quotation_contract_clauses qcc')
            ->select('c.id, c.title, c.description, qcc.sort_order')
            ->join('clauses c', 'c.id = qcc.clause_id')
            ->where('qcc.quotation_contract_id', $quotationContractId)
            ->orderBy('qcc.sort_order', 'ASC')
            ->orderBy('qcc.id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<int, int> $clauseIds
     * @return array<string, string>
     */
    public function validateClauseIds(array $clauseIds): array
    {
        if ($clauseIds === []) {
            return [
                'clauseIds' => 'At least one valid clause id is required.',
            ];
        }

        $rows = model(ContractClauseModel::class)->whereIn('id', $clauseIds)->findAll();
        $foundIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        $foundIds = array_values(array_filter($foundIds, static fn (int $id): bool => $id > 0));

        $missing = array_values(array_diff($clauseIds, $foundIds));
        if ($missing !== []) {
            return [
                'clauseIds' => 'Clause ids not found: ' . implode(', ', $missing) . '.',
            ];
        }

        return [];
    }

    /**
     * @param array<int, int> $clauseIds
     */
    public function syncAssignedClauses(int $quotationContractId, array $clauseIds): void
    {
        $pivotModel = model(QuotationContractClauseModel::class);
        $pivotModel->where('quotation_contract_id', $quotationContractId)->delete();

        foreach (array_values($clauseIds) as $index => $clauseId) {
            $pivotModel->insert([
                'quotation_contract_id' => $quotationContractId,
                'clause_id' => $clauseId,
                'sort_order' => $index,
            ]);
        }
    }
}
