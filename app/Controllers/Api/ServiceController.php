<?php

namespace App\Controllers\Api;

use App\Models\CategoryModel;
use App\Models\ServiceModel;

class ServiceController extends BaseApiController
{
    public function index()
    {
        $serviceModel = new ServiceModel();
        $categoryId = $this->request->getGet('category_id');

        $services = $serviceModel->withCategories($categoryId !== null ? (int) $categoryId : null);

        return $this->res->ok($services, 'Services retrieved successfully');
    }

    public function byCategory(int $categoryId)
    {
        $categoryModel = new CategoryModel();
        $category = $categoryModel->find($categoryId);
        if (!is_array($category)) {
            return $this->res->notFound('Category not found');
        }

        $serviceModel = new ServiceModel();
        $services = $serviceModel->withCategories($categoryId);

        return $this->res->ok([
            'category' => $category,
            'services' => $services,
        ], 'Services retrieved successfully');
    }

    public function show(int $id)
    {
        $serviceModel = new ServiceModel();
        $service = $serviceModel->findDetailed($id);

        if (!is_array($service)) {
            return $this->res->notFound('Service not found');
        }

        return $this->res->ok($service, 'Service retrieved successfully');
    }

    public function store()
    {
        $data = $this->getRequestData(false);
        $categoryIds = $this->normalizeCategoryIds($data);
        $errors = $this->validateService($data, $categoryIds);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $categoryModel = new CategoryModel();
        $validCategoryIds = $this->filterExistingCategoryIds($categoryModel, $categoryIds);
        if ($validCategoryIds === []) {
            return $this->res->badRequest('Category not found.', ['category_ids' => 'At least one valid category is required.']);
        }

        $serviceModel = new ServiceModel();
        $slug = $this->resolveSlug((string) ($data['slug'] ?? ''), (string) $data['name']);
        if ($serviceModel->findBySlug($slug) !== null) {
            return $this->res->badRequest('Service slug already exists.', ['slug' => 'Slug must be unique.']);
        }

        $payload = [
            'name' => trim((string) $data['name']),
            'slug' => $slug,
            'description' => trim((string) ($data['description'] ?? '')),
            'icon' => trim((string) ($data['icon'] ?? '')) ?: null,
            'is_active' => $this->normalizeBool($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        $serviceModel->insert($payload);
        $serviceId = (int) $serviceModel->getInsertID();
        $serviceModel->syncCategories($serviceId, $validCategoryIds);

        return $this->res->created($serviceModel->findDetailed($serviceId), 'Service created successfully');
    }

    public function update(int $id)
    {
        $serviceModel = new ServiceModel();
        $service = $serviceModel->find($id);
        if (!is_array($service)) {
            return $this->res->notFound('Service not found');
        }

        $data = $this->getRequestData(false);
        $payload = [];
        $categoryIds = $this->normalizeCategoryIds($data, true);

        if ($categoryIds !== null) {
            $validCategoryIds = $this->filterExistingCategoryIds(new CategoryModel(), $categoryIds);
            if ($validCategoryIds === []) {
                return $this->res->badRequest('Category not found.', ['category_ids' => 'At least one valid category is required.']);
            }
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
                return $this->res->validation(['name' => 'Service name must be between 2 and 160 characters.']);
            }
            $payload['name'] = $name;
        }

        if (isset($data['slug']) || isset($data['name'])) {
            $payload['slug'] = $this->resolveSlug((string) ($data['slug'] ?? ''), (string) ($payload['name'] ?? $service['name']));
            $existing = $serviceModel->findBySlug($payload['slug']);
            if (is_array($existing) && (int) ($existing['id'] ?? 0) !== $id) {
                return $this->res->badRequest('Service slug already exists.', ['slug' => 'Slug must be unique.']);
            }
        }

        if (isset($data['description'])) {
            $payload['description'] = trim((string) $data['description']);
        }

        if (array_key_exists('icon', $data)) {
            $icon = trim((string) $data['icon']);
            $payload['icon'] = $icon !== '' ? $icon : null;
        }

        if (isset($data['is_active'])) {
            $payload['is_active'] = $this->normalizeBool($data['is_active']);
        }

        if (isset($data['sort_order'])) {
            $payload['sort_order'] = (int) $data['sort_order'];
        }

        if ($payload === [] && $categoryIds === null) {
            return $this->res->badRequest('No service fields supplied to update.');
        }

        $serviceModel->update($id, $payload);

        if ($categoryIds !== null) {
            $serviceModel->syncCategories($id, $this->filterExistingCategoryIds(new CategoryModel(), $categoryIds));
        }

        return $this->res->ok($serviceModel->findDetailed($id), 'Service updated successfully');
    }

    public function delete(int $id)
    {
        $serviceModel = new ServiceModel();
        $service = $serviceModel->find($id);
        if (!is_array($service)) {
            return $this->res->notFound('Service not found');
        }

        $serviceModel->delete($id);

        return $this->res->ok(null, 'Service deleted successfully');
    }

    private function validateService(array $data, array $categoryIds): array
    {
        $errors = [];

        if ($categoryIds === []) {
            $errors['category_ids'] = 'At least one category is required.';
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $errors['name'] = 'Service name is required and must be between 2 and 160 characters.';
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug !== '' && mb_strlen($slug) > 180) {
            $errors['slug'] = 'Service slug must not exceed 180 characters.';
        }

        return $errors;
    }

    /**
     * @return array<int, int>|null
     */
    private function normalizeCategoryIds(array $data, bool $allowNull = false): ?array
    {
        if (isset($data['category_ids'])) {
            $value = $data['category_ids'];
            if (!is_array($value)) {
                $value = preg_split('/\s*,\s*/', (string) $value) ?: [];
            }

            return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
        }

        if (isset($data['category_id'])) {
            $id = (int) $data['category_id'];
            return $id > 0 ? [$id] : [];
        }

        return $allowNull ? null : [];
    }

    /**
     * @param array<int, int> $categoryIds
     * @return array<int, int>
     */
    private function filterExistingCategoryIds(CategoryModel $categoryModel, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $categoryModel->whereIn('id', $categoryIds)->findAll();
        $existingIds = array_map(static fn (array $category): int => (int) $category['id'], $rows);

        return array_values(array_intersect($categoryIds, $existingIds));
    }

    private function resolveSlug(string $slug, string $name): string
    {
        $value = trim($slug !== '' ? $slug : $name);
        $value = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $value = trim($value, '-');

        return $value !== '' ? $value : 'service-' . time();
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
