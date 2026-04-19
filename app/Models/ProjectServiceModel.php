<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectServiceModel extends Model
{
    protected $table = 'project_services';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'project_id',
        'service_id',
        'created_at',
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';

    public function replaceServices(int $projectId, array $serviceIds): bool
    {
        $db = $this->db;
        $db->table($this->table)->where('project_id', $projectId)->delete();

        if ($serviceIds === []) {
            return true;
        }

        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach (array_values(array_unique($serviceIds)) as $serviceId) {
            $id = (int) $serviceId;
            if ($id < 1) {
                continue;
            }

            $rows[] = [
                'project_id' => $projectId,
                'service_id' => $id,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return true;
        }

        return $db->table($this->table)->insertBatch($rows) !== false;
    }

    /**
     * @param array<int, int> $projectIds
     * @return array<int, array<int, string>>
     */
    public function getServiceNamesByProjectIds(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn (int $id): bool => $id > 0)));
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->db->table('project_services ps')
            ->select('ps.project_id, s.id AS service_id, s.name')
            ->join('services s', 's.id = ps.service_id')
            ->whereIn('ps.project_id', $projectIds)
            ->orderBy('s.sort_order', 'ASC')
            ->orderBy('s.name', 'ASC')
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = (int) ($row['project_id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            if ($projectId < 1 || $name === '') {
                continue;
            }

            $map[$projectId][] = $name;
        }

        foreach ($map as $projectId => $names) {
            $map[$projectId] = array_values(array_unique($names));
        }

        return $map;
    }

    /**
     * @param array<int, int> $projectIds
     * @return array<int, array<int, int>>
     */
    public function getServiceIdsByProjectIds(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds), static fn (int $id): bool => $id > 0)));
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->select('project_id, service_id')
            ->whereIn('project_id', $projectIds)
            ->orderBy('id', 'ASC')
            ->findAll();

        $map = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $projectId = (int) ($row['project_id'] ?? 0);
            $serviceId = (int) ($row['service_id'] ?? 0);
            if ($projectId < 1 || $serviceId < 1) {
                continue;
            }

            $map[$projectId][] = $serviceId;
        }

        foreach ($map as $projectId => $ids) {
            $map[$projectId] = array_values(array_unique($ids));
        }

        return $map;
    }
}
