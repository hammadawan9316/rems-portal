<?php

namespace App\Models;

use CodeIgniter\Model;

class ContractModel extends Model
{
    protected $table = 'contracts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'template_name',
        'template_description',
        'contract_name',
        'contract_description',
        'message',
        'owner_name',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateContracts(string $search = '', int $perPage = 20, int $offset = 0): array
    {
        $search = trim($search);

        $countBuilder = $this->builder()
            ->select('COUNT(*) AS total', false);

        if ($search !== '') {
            $countBuilder->groupStart()
                ->like('template_name', $search)
                ->orLike('template_description', $search)
                ->orLike('contract_name', $search)
                ->orLike('contract_description', $search)
                ->orLike('message', $search)
                ->orLike('owner_name', $search)
                ->groupEnd();
        }

        $totalRow = $countBuilder->get()->getRowArray();
        $total = (int) ($totalRow['total'] ?? 0);

        $items = $this->orderBy('id', 'DESC')
            ->limit($perPage, $offset)
            ->findAll();

        if ($items === []) {
            return [
                'items' => [],
                'total' => $total,
            ];
        }

        return [
            'items' => $this->attachClauses($items),
            'total' => $total,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $contracts
     * @return array<int, array<string, mixed>>
     */
    public function attachClauses(array $contracts): array
    {
        if ($contracts === []) {
            return [];
        }

        $contractIds = array_map(static fn (array $contract): int => (int) ($contract['id'] ?? 0), $contracts);
        $contractIds = array_values(array_filter($contractIds, static fn (int $id): bool => $id > 0));
        if ($contractIds === []) {
            return $contracts;
        }

        $clauseRows = $this->db->table('contract_clauses cc')
            ->select('cc.contract_id, cc.clause_id, cc.sort_order, c.title, c.description')
            ->join('clauses c', 'c.id = cc.clause_id')
            ->whereIn('cc.contract_id', $contractIds)
            ->orderBy('cc.sort_order', 'ASC')
            ->orderBy('cc.id', 'ASC')
            ->get()
            ->getResultArray();

        $clausesByContract = [];
        foreach ($clauseRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $contractId = (int) ($row['contract_id'] ?? 0);
            if ($contractId < 1) {
                continue;
            }

            $clausesByContract[$contractId][] = [
                'id' => (int) ($row['clause_id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        foreach ($contracts as &$contract) {
            if (!is_array($contract)) {
                continue;
            }

            $contractId = (int) ($contract['id'] ?? 0);
            $contract['clauses'] = $clausesByContract[$contractId] ?? [];
        }
        unset($contract);

        return $contracts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $id): ?array
    {
        $contract = $this->find($id);
        if (!is_array($contract)) {
            return null;
        }

        $contracts = $this->attachClauses([$contract]);

        return $contracts[0] ?? null;
    }
}
