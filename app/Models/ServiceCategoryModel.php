<?php

namespace App\Models;

use CodeIgniter\Model;

class ServiceCategoryModel extends Model
{
    protected $table = 'service_categories';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'service_id',
        'category_id',
        'created_at',
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';

    public function replaceCategories(int $serviceId, array $categoryIds): bool
    {
        $db = $this->db;
        $db->table($this->table)->where('service_id', $serviceId)->delete();

        if ($categoryIds === []) {
            return true;
        }

        $rows = [];
        $now = date('Y-m-d H:i:s');
        foreach (array_values(array_unique($categoryIds)) as $categoryId) {
            $rows[] = [
                'service_id' => $serviceId,
                'category_id' => (int) $categoryId,
                'created_at' => $now,
            ];
        }

        return $db->table($this->table)->insertBatch($rows) !== false;
    }

    public function findCategoryIdsByServiceId(int $serviceId): array
    {
        $rows = $this->where('service_id', $serviceId)->findAll();

        return array_map(static fn (array $row): int => (int) $row['category_id'], $rows);
    }

    public function findServiceIdsByCategoryId(int $categoryId): array
    {
        $rows = $this->where('category_id', $categoryId)->findAll();

        return array_map(static fn (array $row): int => (int) $row['service_id'], $rows);
    }
}
