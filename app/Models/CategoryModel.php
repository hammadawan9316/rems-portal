<?php

namespace App\Models;

use CodeIgniter\Model;

class CategoryModel extends Model
{
    protected $table = 'categories';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'name',
        'slug',
        'description',
        'image',
        'is_active',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findBySlug(string $slug): ?array
    {
        $category = $this->where('slug', trim($slug))->first();

        return is_array($category) ? $category : null;
    }

    public function withServiceCount(): array
    {
        return $this->select('categories.*, COUNT(DISTINCT service_categories.service_id) AS service_count')
            ->join('service_categories', 'service_categories.category_id = categories.id', 'left')
            ->groupBy('categories.id')
            ->orderBy('categories.sort_order', 'ASC')
            ->orderBy('categories.name', 'ASC')
            ->findAll();
    }

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateWithServiceCount(string $search = '', int $perPage = 20, int $offset = 0): array
    {
        $search = trim($search);

        $countBuilder = $this->builder()
            ->select('COUNT(DISTINCT categories.id) AS total', false)
            ->join('service_categories', 'service_categories.category_id = categories.id', 'left');

        if ($search !== '') {
            $countBuilder->groupStart()
                ->like('categories.name', $search)
                ->orLike('categories.slug', $search)
                ->orLike('categories.description', $search)
                ->groupEnd();
        }

        $totalRow = $countBuilder->get()->getRowArray();
        $total = (int) ($totalRow['total'] ?? 0);

        $itemsBuilder = $this->builder()
            ->select('categories.*, COUNT(DISTINCT service_categories.service_id) AS service_count')
            ->join('service_categories', 'service_categories.category_id = categories.id', 'left')
            ->groupBy('categories.id')
            ->orderBy('categories.sort_order', 'ASC')
            ->orderBy('categories.name', 'ASC');

        if ($search !== '') {
            $itemsBuilder->groupStart()
                ->like('categories.name', $search)
                ->orLike('categories.slug', $search)
                ->orLike('categories.description', $search)
                ->groupEnd();
        }

        $items = $itemsBuilder
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
