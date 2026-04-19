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
}
