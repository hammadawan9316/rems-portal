<?php

namespace App\Controllers\Api;

use App\Models\CategoryModel;
use App\Models\ServiceModel;

class CategoryController extends BaseApiController
{
    public function index()
    {
        $categoryModel = new CategoryModel();
        $params = $this->getListQueryParams();
        $result = $categoryModel->paginateWithServiceCount($params['search'], $params['perPage'], $params['offset']);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Categories retrieved successfully');
    }

    public function show(int $id)
    {
        $categoryModel = new CategoryModel();
        $serviceModel = new ServiceModel();

        $category = $categoryModel->find($id);
        if (!is_array($category)) {
            return $this->res->notFound('Category not found');
        }

        $category['services'] = $serviceModel->withCategories((int) $category['id']);

        return $this->res->ok($category, 'Category retrieved successfully');
    }

    public function store()
    {
        $data = $this->getRequestData(false);
        $uploadedImage = $this->uploadCategoryImage();
        if (isset($uploadedImage['error'])) {
            return $this->res->validation(['image' => (string) $uploadedImage['error']]);
        }

        if (isset($uploadedImage['path'])) {
            $data['image'] = $uploadedImage['path'];
        }

        $errors = $this->validateCategory($data);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $categoryModel = new CategoryModel();
        $payload = [
            'name' => trim((string) $data['name']),
            'slug' => $this->resolveSlug((string) ($data['slug'] ?? ''), (string) $data['name']),
            'description' => trim((string) ($data['description'] ?? '')),
            'image' => trim((string) ($data['image'] ?? '')) ?: null,
            'is_active' => $this->normalizeBool($data['is_active'] ?? true),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        if ($categoryModel->findBySlug($payload['slug']) !== null) {
            return $this->res->badRequest('Category slug already exists.', ['slug' => 'Slug must be unique.']);
        }

        $categoryModel->insert($payload);
        $categoryId = (int) $categoryModel->getInsertID();

        return $this->res->created($categoryModel->find($categoryId), 'Category created successfully');
    }

    public function update(int $id)
    {
        $categoryModel = new CategoryModel();
        $category = $categoryModel->find($id);
        if (!is_array($category)) {
            return $this->res->notFound('Category not found');
        }

        $data = $this->getRequestData(false);
        $uploadedImage = $this->uploadCategoryImage();
        if (isset($uploadedImage['error'])) {
            return $this->res->validation(['image' => (string) $uploadedImage['error']]);
        }

        if (isset($uploadedImage['path'])) {
            $data['image'] = $uploadedImage['path'];
        }

        $payload = [];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
                return $this->res->validation(['name' => 'Category name must be between 2 and 160 characters.']);
            }
            $payload['name'] = $name;
        }

        if (isset($data['slug']) || isset($data['name'])) {
            $payload['slug'] = $this->resolveSlug((string) ($data['slug'] ?? ''), (string) ($payload['name'] ?? $category['name']));
            $existing = $categoryModel->findBySlug($payload['slug']);
            if (is_array($existing) && (int) ($existing['id'] ?? 0) !== $id) {
                return $this->res->badRequest('Category slug already exists.', ['slug' => 'Slug must be unique.']);
            }
        }

        if (isset($data['description'])) {
            $payload['description'] = trim((string) $data['description']);
        }

        if (array_key_exists('image', $data)) {
            $image = trim((string) $data['image']);
            if ($image !== '' && mb_strlen($image) > 190) {
                return $this->res->validation(['image' => 'Category image must not exceed 190 characters.']);
            }
            $payload['image'] = $image !== '' ? $image : null;
        }

        if (isset($data['is_active'])) {
            $payload['is_active'] = $this->normalizeBool($data['is_active']);
        }

        if (isset($data['sort_order'])) {
            $payload['sort_order'] = (int) $data['sort_order'];
        }

        if ($payload === []) {
            return $this->res->badRequest('No category fields supplied to update.');
        }

        $categoryModel->update($id, $payload);

        return $this->res->ok($categoryModel->find($id), 'Category updated successfully');
    }

    public function delete(int $id)
    {
        $categoryModel = new CategoryModel();
        $category = $categoryModel->find($id);
        if (!is_array($category)) {
            return $this->res->notFound('Category not found');
        }

        $categoryModel->delete($id);

        return $this->res->ok(null, 'Category deleted successfully');
    }

    private function validateCategory(array $data): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $errors['name'] = 'Category name is required and must be between 2 and 160 characters.';
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug !== '' && mb_strlen($slug) > 180) {
            $errors['slug'] = 'Category slug must not exceed 180 characters.';
        }

        $image = trim((string) ($data['image'] ?? ''));
        if ($image !== '' && mb_strlen($image) > 190) {
            $errors['image'] = 'Category image must not exceed 190 characters.';
        }

        return $errors;
    }

    private function resolveSlug(string $slug, string $name): string
    {
        $value = trim($slug !== '' ? $slug : $name);
        $value = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '');
        $value = trim($value, '-');

        return $value !== '' ? $value : 'category-' . time();
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{path?:string,error?:string}
     */
    private function uploadCategoryImage(): array
    {
        $file = $this->request->getFile('image');
        if ($file === null) {
            return [];
        }

        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            return [];
        }

        $directory = 'categories/' . date('Y') . '/' . date('m');
        $result = $this->uploadService->uploadSingle(
            $this->request,
            'image',
            $directory,
            ['jpg', 'jpeg', 'png', 'webp', 'gif'],
            10240
        );

        if (($result['status'] ?? false) !== true) {
            $errors = $result['errors'] ?? [];
            $errorMessage = is_array($errors) ? (string) ($errors['image'] ?? $result['message'] ?? 'Image upload failed.') : (string) ($result['message'] ?? 'Image upload failed.');
            return ['error' => $errorMessage];
        }

        $uploadData = $result['data'] ?? [];
        $relativePath = trim((string) ($uploadData['relative_path'] ?? ''));
        if ($relativePath === '') {
            return ['error' => 'Image upload failed due to missing file path.'];
        }

        return ['path' => $relativePath];
    }
}
