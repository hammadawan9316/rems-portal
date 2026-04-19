<?php

namespace App\Models;

use CodeIgniter\Model;

class ServiceModel extends Model
{
    protected $table = 'services';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'name',
        'slug',
        'description',
        'icon',
        'is_active',
        'sort_order',
        'created_at',
        'updated_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    private ServiceCategoryModel $serviceCategoryModel;

    public function __construct(?\CodeIgniter\Database\ConnectionInterface $db = null, ?\CodeIgniter\Validation\ValidationInterface $validation = null)
    {
        parent::__construct($db, $validation);
        $this->serviceCategoryModel = new ServiceCategoryModel();
    }

    public function findBySlug(string $slug): ?array
    {
        $service = $this->where('slug', trim($slug))->first();

        return is_array($service) ? $service : null;
    }

    public function withCategories(?int $categoryId = null): array
    {
        $builder = $this->select('services.*')
            ->distinct()
            ->join('service_categories', 'service_categories.service_id = services.id')
            ->join('categories', 'categories.id = service_categories.category_id')
            ->orderBy('services.sort_order', 'ASC')
            ->orderBy('services.name', 'ASC');

        if ($categoryId !== null) {
            $builder->where('service_categories.category_id', $categoryId);
        }

        $services = $builder->findAll();

        return $this->attachCategories($services);
    }

    public function findDetailed(int $id): ?array
    {
        $service = $this->select('services.*')
            ->where('services.id', $id)
            ->first();

        if (!is_array($service)) {
            return null;
        }

        return $this->attachCategories([$service])[0] ?? null;
    }

    public function syncCategories(int $serviceId, array $categoryIds): bool
    {
        return $this->serviceCategoryModel->replaceCategories($serviceId, $categoryIds);
    }

    public function findCategoryIds(int $serviceId): array
    {
        return $this->serviceCategoryModel->findCategoryIdsByServiceId($serviceId);
    }

    /**
     * @param array<int, array<string, mixed>> $services
     * @return array<int, array<string, mixed>>
     */
    private function attachCategories(array $services): array
    {
        if ($services === []) {
            return [];
        }

        $serviceIds = array_map(static fn (array $service): int => (int) ($service['id'] ?? 0), $services);
        $serviceIds = array_values(array_filter($serviceIds));
        if ($serviceIds === []) {
            return $services;
        }

        $rows = $this->db->table('service_categories sc')
            ->select('sc.service_id, c.id AS category_id, c.name, c.slug, c.description, c.is_active, c.sort_order')
            ->join('categories c', 'c.id = sc.category_id')
            ->whereIn('sc.service_id', $serviceIds)
            ->orderBy('c.sort_order', 'ASC')
            ->orderBy('c.name', 'ASC')
            ->get()
            ->getResultArray();

        $categoriesByService = [];
        foreach ($rows as $row) {
            $serviceId = (int) ($row['service_id'] ?? 0);
            $categoriesByService[$serviceId][] = [
                'id' => (int) ($row['category_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'description' => $row['description'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? false),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        foreach ($services as &$service) {
            $serviceId = (int) ($service['id'] ?? 0);
            $service['categories'] = $categoriesByService[$serviceId] ?? [];
            $service['category_ids'] = array_map(static fn (array $category): int => (int) $category['id'], $service['categories']);
        }
        unset($service);

        return $services;
    }
}
