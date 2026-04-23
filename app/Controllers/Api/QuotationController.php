<?php

namespace App\Controllers\Api;

use App\Models\CategoryModel;
use App\Models\CustomerModel;
use App\Models\ProjectModel;
use App\Models\ProjectServiceModel;
use App\Models\QuotationModel;
use App\Models\ServiceModel;
use App\Libraries\SquareProjectQueueService;
use App\Libraries\SquareService;

class QuotationController extends BaseApiController
{
    private const STATUS_REQUESTED = 'requested';
    private const STATUS_PENDING = 'pending';
    private const STATUS_ACCEPTED = 'accepted';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_SQUARE_FAILED = 'square_failed';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
        self::STATUS_SQUARE_FAILED,
    ];

    public function store()
    {
        return $this->submit();
    }

    public function submit()
    {
        $data = $this->normalizeIncomingPayload($this->getRequestData(false));
        $projectItems = $this->extractProjectItems($data);

        if ($projectItems === []) {
            return $this->res->badRequest('At least one project is required.', [
                'projects' => 'Provide a projects array with one or more items.',
            ]);
        }

        $requestErrors = $this->validateNormalizedRequest($data);
        if ($requestErrors !== []) {
            return $this->res->validation($requestErrors);
        }

        $projectErrors = $this->validateProjectItems($projectItems);
        if ($projectErrors !== []) {
            return $this->res->validation($projectErrors);
        }

        $taxonomyResolution = $this->resolveProjectTaxonomy($projectItems);
        if (($taxonomyResolution['errors'] ?? []) !== []) {
            return $this->res->validation($taxonomyResolution['errors']);
        }

        $projectItems = $taxonomyResolution['projects'] ?? $projectItems;

        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();
        $projectServiceModel = new ProjectServiceModel();
        $customerModel = new CustomerModel();
        $squareQueue = new SquareProjectQueueService();
        $square = new SquareService();
        $isSquareConfigured = $square->isConfigured();

        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($customerId < 1) {
            return $this->res->badRequest('Customer id is required.', [
                'customer_id' => 'A valid customer id is required.',
            ]);
        }

        $customer = $customerModel->find($customerId);
        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found.');
        }

        $quoteNumber = $quotationModel->generateQuoteNumber();

        $quotationPayload = [
            'customer_id' => $customerId,
            'quote_number' => $quoteNumber,
            'description' => $this->normalizeNullableText($data['description'] ?? ($data['title'] ?? null)),
            'status' => self::STATUS_PENDING,
            'notes' => $this->normalizeNullableText($data['notes'] ?? null),
            'submitted_at' => date('Y-m-d H:i:s'),
            'discount_type' => $this->normalizeDiscountType($data['discount_type'] ?? ($data['discountType'] ?? null)),
            'discount_value' => $this->normalizeDecimalValue($data['discount_value'] ?? ($data['discountValue'] ?? null)),
            'discount_scope' => $this->normalizeDiscountScope($data['discount_scope'] ?? ($data['discountScope'] ?? null)),
        ];

        $quotationModel->insert($quotationPayload);

        $quotationId = (int) $quotationModel->getInsertID();
        if ($quotationId < 1) {
            return $this->res->serverError('Quotation could not be created.');
        }

        $createdProjects = [];
        foreach ($projectItems as $item) {
            $projectData = [
                'customer_id' => $customerId,
                'quotation_id' => $quotationId,
                'category_id' => (int) ($item['category_id'] ?? 0) ?: null,
                'project_title' => $item['project_title'],
                'project_description' => $item['project_description'],
                'scope' => $item['scope'],
                'estimate_type' => $item['estimate_type'],
                'plans_url' => null,
                'zip_code' => $item['zip_code'],
                'deadline' => $item['deadline'],
                'delivery_date' => $item['delivery_date'],
                'deadline_date' => $item['deadline_date'],
                'estimated_amount' => $item['estimated_amount'],
                'payment_type' => $item['payment_type'],
                'hourly_hours' => $item['hourly_hours'],
                'status' => 'submitted',
            ];

            $projectModel->insert($projectData);
            $projectId = (int) $projectModel->getInsertID();
            $projectServiceModel->replaceServices($projectId, is_array($item['service_ids'] ?? null) ? $item['service_ids'] : []);

            $savedProject = $projectModel->find($projectId);
            if (is_array($savedProject)) {
                $createdProjects[] = $savedProject;
            }
        }

        if ($isSquareConfigured) {
            $squareQueue->enqueue($quotationId);
        }

        $quotation = $quotationModel->find($quotationId);
        if (!is_array($quotation)) {
            $quotation = [];
        }
        $quotation = $this->formatQuotationForResponse($quotation, $customer);

        $quotation['projects'] = $this->formatProjectsForResponse($createdProjects);
        $quotation['project_count'] = count($createdProjects);

        return $this->res->created($quotation, 'Quotation created successfully from projects.');
    }

    public function update(int $id)
    {
        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();
        $projectServiceModel = new ProjectServiceModel();
        $customerModel = new CustomerModel();

        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $data = $this->normalizeIncomingPayload($this->getRequestData(false));
        $quotationPayload = [];

        if (array_key_exists('customer_id', $data)) {
            $customerId = (int) ($data['customer_id'] ?? 0);
            if ($customerId < 1) {
                return $this->res->badRequest('Customer id is required.', [
                    'customer_id' => 'A valid customer id is required.',
                ]);
            }

            $customer = $customerModel->find($customerId);
            if (!is_array($customer)) {
                return $this->res->notFound('Customer not found.');
            }

            $quotationPayload['customer_id'] = $customerId;
        }

        if (array_key_exists('description', $data) || array_key_exists('title', $data)) {
            $quotationPayload['description'] = $this->normalizeNullableText($data['description'] ?? ($data['title'] ?? null));
        }

        if (array_key_exists('status', $data)) {
            $statusResult = $this->resolveStatusFilter($data['status']);
            if (is_array($statusResult) && isset($statusResult['error'])) {
                return $this->res->badRequest('Invalid quotation status.', [
                    'status' => (string) $statusResult['error'],
                ]);
            }

            $quotationPayload['status'] = is_string($statusResult) ? $statusResult : self::STATUS_PENDING;
        }

        if (array_key_exists('notes', $data)) {
            $quotationPayload['notes'] = $this->normalizeNullableText($data['notes']);
        }

        if (array_key_exists('discount_type', $data) || array_key_exists('discountType', $data)) {
            $quotationPayload['discount_type'] = $this->normalizeDiscountType($data['discount_type'] ?? ($data['discountType'] ?? null));
        }

        if (array_key_exists('discount_value', $data) || array_key_exists('discountValue', $data)) {
            $quotationPayload['discount_value'] = $this->normalizeDecimalValue($data['discount_value'] ?? ($data['discountValue'] ?? null));
        }

        if (array_key_exists('discount_scope', $data) || array_key_exists('discountScope', $data)) {
            $quotationPayload['discount_scope'] = $this->normalizeDiscountScope($data['discount_scope'] ?? ($data['discountScope'] ?? null));
        }

        $shouldReplaceProjects = isset($data['projects']) || $this->looksLikeProjectPayload($data);
        $projectItems = [];

        if ($shouldReplaceProjects) {
            $projectItems = $this->extractProjectItems($data);
            if ($projectItems === []) {
                return $this->res->badRequest('At least one project is required.', [
                    'projects' => 'Provide a projects array with one or more items.',
                ]);
            }

            $projectErrors = $this->validateProjectItems($projectItems);
            if ($projectErrors !== []) {
                return $this->res->validation($projectErrors);
            }

            $taxonomyResolution = $this->resolveProjectTaxonomy($projectItems);
            if (($taxonomyResolution['errors'] ?? []) !== []) {
                return $this->res->validation($taxonomyResolution['errors']);
            }

            $projectItems = $taxonomyResolution['projects'] ?? $projectItems;
        }

        if ($quotationPayload === [] && !$shouldReplaceProjects) {
            return $this->res->badRequest('No quotation fields supplied to update.');
        }

        if ($quotationPayload !== []) {
            $quotationModel->update($id, $quotationPayload);
        }

        $effectiveCustomerId = (int) ($quotationPayload['customer_id'] ?? ($quotation['customer_id'] ?? 0));
        $customer = $customerModel->find($effectiveCustomerId);
        if (!is_array($customer)) {
            return $this->res->notFound('Customer not found.');
        }

        if ($shouldReplaceProjects) {
            $existingProjects = $projectModel
                ->where('quotation_id', $id)
                ->orderBy('id', 'ASC')
                ->findAll();

            $existingProjectIds = array_map(static fn (array $project): int => (int) ($project['id'] ?? 0), $existingProjects);
            $existingProjectIds = array_values(array_filter($existingProjectIds, static fn (int $projectId): bool => $projectId > 0));

            if ($existingProjectIds !== []) {
                $projectServiceModel->whereIn('project_id', $existingProjectIds)->delete();
            }

            $projectModel->where('quotation_id', $id)->delete();

            foreach ($projectItems as $item) {
                $projectData = [
                    'customer_id' => $effectiveCustomerId,
                    'quotation_id' => $id,
                    'category_id' => (int) ($item['category_id'] ?? 0) ?: null,
                    'project_title' => $item['project_title'],
                    'project_description' => $item['project_description'],
                    'scope' => $item['scope'],
                    'estimate_type' => $item['estimate_type'],
                    'plans_url' => null,
                    'zip_code' => $item['zip_code'],
                    'deadline' => $item['deadline'],
                    'delivery_date' => $item['delivery_date'],
                    'deadline_date' => $item['deadline_date'],
                    'estimated_amount' => $item['estimated_amount'],
                    'payment_type' => $item['payment_type'],
                    'hourly_hours' => $item['hourly_hours'],
                    'status' => 'submitted',
                ];

                $projectModel->insert($projectData);
                $projectId = (int) $projectModel->getInsertID();
                $projectServiceModel->replaceServices($projectId, is_array($item['service_ids'] ?? null) ? $item['service_ids'] : []);
            }
        }

        $updatedQuotation = $quotationModel->find($id);
        if (!is_array($updatedQuotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $updatedProjects = $projectModel
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        $updatedQuotation = $this->formatQuotationForResponse($updatedQuotation, $customer);
        $updatedQuotation['projects'] = $this->formatProjectsForResponse($updatedProjects);
        $updatedQuotation['project_count'] = count($updatedProjects);

        return $this->res->ok($updatedQuotation, 'Quotation updated successfully');
    }

    public function index()
    {
        $params = $this->getListQueryParams();
        $customerId = (int) ($this->request->getGet('customer_id') ?? 0);
        $statusResult = $this->resolveStatusFilter($this->request->getGet('status'));
        if (is_array($statusResult) && isset($statusResult['error'])) {
            return $this->res->badRequest('Invalid quotation status filter.', [
                'status' => (string) $statusResult['error'],
            ]);
        }
        $status = is_string($statusResult) ? $statusResult : null;

        $result = $this->paginateFormattedQuotations($customerId > 0 ? $customerId : null, $params['search'], $params['perPage'], $params['offset'], $status);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Quotations retrieved successfully');
    }

    public function requested()
    {
        $params = $this->getListQueryParams();
        $result = $this->paginateFormattedQuotations(null, $params['search'], $params['perPage'], $params['offset'], self::STATUS_REQUESTED);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Requested quotations retrieved successfully');
    }

    public function show(int $id)
    {
        $quotationModel = new QuotationModel();
        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

        $customer = model(CustomerModel::class)->find((int) ($quotation['customer_id'] ?? 0));
        $quotation = $this->formatQuotationForResponse($quotation, is_array($customer) ? $customer : null);

        $projectModel = new ProjectModel();

        $projects = $projectModel
            ->where('quotation_id', $id)
            ->orderBy('id', 'ASC')
            ->findAll();

        $projects = $this->formatProjectsForResponse($projects);

        $quotation['projects'] = $projects;
        $quotation['project_count'] = count($projects);

        return $this->res->ok($quotation, 'Quotation retrieved successfully');
    }

    public function byCustomer(int $customerId)
    {
        if ($customerId < 1) {
            return $this->res->badRequest('Valid customer id is required.');
        }

        $params = $this->getListQueryParams();
        $statusResult = $this->resolveStatusFilter($this->request->getGet('status'));
        if (is_array($statusResult) && isset($statusResult['error'])) {
            return $this->res->badRequest('Invalid quotation status filter.', [
                'status' => (string) $statusResult['error'],
            ]);
        }
        $status = is_string($statusResult) ? $statusResult : null;

        $result = $this->paginateFormattedQuotations($customerId, $params['search'], $params['perPage'], $params['offset'], $status);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Customer quotations retrieved successfully');
    }

    public function requestedByCustomer(int $customerId)
    {
        if ($customerId < 1) {
            return $this->res->badRequest('Valid customer id is required.');
        }

        $params = $this->getListQueryParams();
        $result = $this->paginateFormattedQuotations($customerId, $params['search'], $params['perPage'], $params['offset'], self::STATUS_REQUESTED);

        return $this->res->paginated($result['items'], $result['total'], $params['page'], $params['perPage'], 'Customer requested quotations retrieved successfully');
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     * @return array<int, array<string, mixed>>
     */
    private function formatProjectsForResponse(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $projectIds = array_map(static fn (array $project): int => (int) ($project['id'] ?? 0), $projects);
        $projectIds = array_values(array_filter($projectIds, static fn (int $id): bool => $id > 0));

        $categoryIds = array_map(static fn (array $project): int => (int) ($project['category_id'] ?? 0), $projects);
        $categoryIds = array_values(array_unique(array_filter($categoryIds, static fn (int $id): bool => $id > 0)));

        $categoriesById = [];
        if ($categoryIds !== []) {
            $categoryRows = model(CategoryModel::class)->whereIn('id', $categoryIds)->findAll();
            foreach ($categoryRows as $category) {
                if (!is_array($category)) {
                    continue;
                }

                $categoryId = (int) ($category['id'] ?? 0);
                if ($categoryId > 0) {
                    $categoriesById[$categoryId] = trim((string) ($category['name'] ?? ''));
                }
            }
        }

        $projectServiceModel = new ProjectServiceModel();
        $servicesByProject = $projectServiceModel->getServiceNamesByProjectIds($projectIds);
        $serviceIdsByProject = $projectServiceModel->getServiceIdsByProjectIds($projectIds);

        foreach ($projects as &$project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = (int) ($project['id'] ?? 0);
            $categoryId = (int) ($project['category_id'] ?? 0);

            $project['category'] = $categoriesById[$categoryId] ?? '';
            $project['services'] = $servicesByProject[$projectId] ?? [];
            $project['service_ids'] = $serviceIdsByProject[$projectId] ?? [];
            $project['payment_type'] = (string) ($project['payment_type'] ?? 'fixed_rate');
            $project['hourly_hours'] = $project['hourly_hours'] ?? null;

            unset($project['nature'], $project['trades']);
        }
        unset($project);

        return $projects;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extractProjectItems(array $data): array
    {
        $items = [];

        $projects = $data['projects'] ?? null;
        if (is_array($projects) && $projects !== []) {
            foreach ($projects as $project) {
                if (!is_array($project)) {
                    continue;
                }

                $items[] = $this->normalizeProjectItem($project);
            }

            return $items;
        }

        if (!$this->looksLikeProjectPayload($data)) {
            return [];
        }

        return [$this->normalizeProjectItem($data)];
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private function normalizeProjectItem(array $project): array
    {
        return [
            'project_title' => trim((string) ($project['project_title'] ?? '')),
            'project_description' => trim((string) ($project['project_description'] ?? ($project['scope'] ?? ''))),
            'estimated_amount' => $this->normalizeMoneyValue($project['estimated_amount'] ?? ($project['amount'] ?? null)),
            'category_id' => $this->normalizeCategoryId($project),
            'service_ids' => $this->normalizeServiceIds($project['services'] ?? ($project['service_ids'] ?? [])),
            'payment_type' => $this->normalizePaymentType($project['payment_type'] ?? ($project['paymentType'] ?? 'fixed_rate')),
            'hourly_hours' => $this->normalizeDecimalValue($project['hourly_hours'] ?? ($project['hours'] ?? null)),
            'scope' => trim((string) ($project['scope'] ?? '')),
            'estimate_type' => trim((string) ($project['estimate_type'] ?? ($project['estimateType'] ?? ''))),
            'zip_code' => trim((string) ($project['zip_code'] ?? ($project['zipCode'] ?? ''))),
            'deadline' => trim((string) ($project['deadline'] ?? '')),
            'delivery_date' => $this->normalizeDateString($project['delivery_date'] ?? ($project['deliveryDate'] ?? null)),
            'deadline_date' => $this->normalizeDateString($project['deadline_date'] ?? ($project['deadlineDate'] ?? null)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeIncomingPayload(array $data): array
    {
        if (array_is_list($data)) {
            $normalized = [];
            $projects = [];

            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if ($this->looksLikeCustomerPayload($item)) {
                    $normalized = array_merge($normalized, $item);
                    continue;
                }

                $projects[] = $item;
            }

            if ($projects !== []) {
                $normalized['projects'] = $projects;
            }

            $data = $normalized;
        }

        $data['client_name'] = trim((string) ($data['client_name'] ?? ($data['name'] ?? '')));
        $data['client_email'] = trim((string) ($data['client_email'] ?? ($data['email'] ?? '')));
        $data['client_phone'] = trim((string) ($data['client_phone'] ?? ($data['phone'] ?? '')));
        $data['company'] = trim((string) ($data['company'] ?? ''));

        if (!isset($data['projects']) && $this->looksLikeProjectPayload($data)) {
            $data['projects'] = [$data];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateNormalizedRequest(array $data): array
    {
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     * @return array<string, string>
     */
    private function validateProjectItems(array $projectItems): array
    {
        $errors = [];

        foreach ($projectItems as $index => $item) {
            if ($item['deadline_date'] !== null && strtotime((string) $item['deadline_date']) === false) {
                $errors['projects.' . $index . '.deadlineDate'] = 'Deadline date must be a valid date.';
            }

            if ($item['delivery_date'] !== null && strtotime((string) $item['delivery_date']) === false) {
                $errors['projects.' . $index . '.deliveryDate'] = 'Delivery date must be a valid date.';
            }

            foreach ($this->validateProjectBillingItem($item, $index) as $field => $message) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, string>
     */
    private function validateProjectBillingItem(array $project, int $index): array
    {
        $errors = [];

        $paymentType = (string) ($project['payment_type'] ?? 'fixed_rate');
        if (!in_array($paymentType, ['fixed_rate', 'hourly'], true)) {
            $errors['projects.' . $index . '.payment_type'] = 'Payment type must be fixed_rate or hourly.';
        }

        if ($paymentType === 'hourly' && (!is_numeric($project['hourly_hours'] ?? null) || (float) $project['hourly_hours'] <= 0)) {
            $errors['projects.' . $index . '.hourly_hours'] = 'Hourly payment requires a valid hours value greater than 0.';
        }

        if ($project['estimated_amount'] !== null && $project['estimated_amount'] !== '' && !is_numeric($project['estimated_amount'])) {
            $errors['projects.' . $index . '.estimated_amount'] = 'Amount must be numeric.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function looksLikeProjectPayload(array $item): bool
    {
        return isset($item['project_title'])
            || isset($item['category'])
            || isset($item['category_id'])
            || isset($item['services']);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function looksLikeCustomerPayload(array $item): bool
    {
        return isset($item['name']) || isset($item['email']) || isset($item['phone']) || isset($item['client_name']);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function normalizeCategoryId(array $item): int
    {
        if (isset($item['category_id'])) {
            $id = (int) $item['category_id'];
            if ($id > 0) {
                return $id;
            }
        }

        if (isset($item['category']) && is_numeric($item['category'])) {
            $id = (int) $item['category'];
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * @return array<int, int>
     */
    private function normalizeServiceIds(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\s*,\s*/', $value) ?: [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $items = array_map('intval', $value);
        $items = array_values(array_unique(array_filter($items, static fn (int $id): bool => $id > 0)));

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     * @return array{projects:array<int, array<string, mixed>>,errors:array<string, string>}
     */
    private function resolveProjectTaxonomy(array $projectItems): array
    {
        $categoryModel = new CategoryModel();
        $serviceModel = new ServiceModel();

        $categories = $categoryModel->findAll();
        $categoryById = [];
        $categoryBySlug = [];
        $categoryByName = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $id = (int) ($category['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $categoryById[$id] = $category;
            $slug = strtolower(trim((string) ($category['slug'] ?? '')));
            if ($slug !== '') {
                $categoryBySlug[$slug] = $category;
            }

            $name = strtolower(trim((string) ($category['name'] ?? '')));
            if ($name !== '') {
                $categoryByName[$name] = $category;
            }
        }

        $services = $serviceModel->withCategories();
        $serviceById = [];
        $serviceBySlug = [];
        $serviceByName = [];
        foreach ($services as $service) {
            if (!is_array($service)) {
                continue;
            }

            $id = (int) ($service['id'] ?? 0);
            if ($id < 1) {
                continue;
            }

            $serviceById[$id] = $service;
            $slug = strtolower(trim((string) ($service['slug'] ?? '')));
            if ($slug !== '') {
                $serviceBySlug[$slug] = $service;
            }

            $name = strtolower(trim((string) ($service['name'] ?? '')));
            if ($name !== '') {
                $serviceByName[$name] = $service;
            }
        }

        $errors = [];
        $resolved = [];

        foreach ($projectItems as $index => $item) {
            $categoryId = (int) ($item['category_id'] ?? 0);
            if ($categoryId < 1) {
                $errors['projects.' . $index . '.category'] = 'Category is required for each project.';
                continue;
            }

            $category = $categoryById[$categoryId] ?? null;

            if (!is_array($category)) {
                $errors['projects.' . $index . '.category'] = 'Category was not found.';
                continue;
            }

            $rawServices = $this->normalizeServiceIds($item['service_ids'] ?? []);
            if ($rawServices === []) {
                $errors['projects.' . $index . '.services'] = 'At least one service is required for each project.';
                continue;
            }

            $serviceIds = [];
            $serviceNames = [];
            $invalid = [];

            foreach ($rawServices as $rawService) {
                $service = null;

                $service = $serviceById[(int) $rawService] ?? null;

                if (!is_array($service)) {
                    $invalid[] = (string) $rawService;
                    continue;
                }

                $serviceCategoryIds = array_map(static fn (array $categoryRow): int => (int) ($categoryRow['id'] ?? 0), is_array($service['categories'] ?? null) ? $service['categories'] : []);
                if (!in_array($categoryId, $serviceCategoryIds, true)) {
                    $invalid[] = (string) $rawService;
                    continue;
                }

                $serviceIds[] = (int) ($service['id'] ?? 0);
                $serviceName = trim((string) ($service['name'] ?? ''));
                if ($serviceName !== '') {
                    $serviceNames[] = $serviceName;
                }
            }

            if ($invalid !== []) {
                $errors['projects.' . $index . '.services'] = 'Invalid service(s) for selected category: ' . implode(', ', $invalid);
                continue;
            }

            $item['category'] = trim((string) ($category['name'] ?? ''));
            $item['services'] = array_values(array_unique($serviceNames));
            $item['category_id'] = $categoryId;
            $item['service_ids'] = array_values(array_unique(array_filter($serviceIds, static fn (int $id): bool => $id > 0)));

            $resolved[] = $item;
        }

        return [
            'projects' => $errors === [] ? $resolved : $projectItems,
            'errors' => $errors,
        ];
    }

    private function normalizePaymentType(mixed $value): string
    {
        $paymentType = strtolower(trim((string) $value));
        return in_array($paymentType, ['fixed_rate', 'hourly'], true) ? $paymentType : 'fixed_rate';
    }

    private function normalizeDiscountType(mixed $value): ?string
    {
        $discountType = strtolower(trim((string) $value));
        if ($discountType === 'fixed') {
            return 'fixed_amount';
        }

        return in_array($discountType, ['fixed_amount', 'percentage'], true) ? $discountType : null;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $text = trim($value);
        return $text === '' ? null : $text;
    }

    /**
     * @param array<string, mixed> $quotation
     * @param array<string, mixed>|null $customer
     * @return array<string, mixed>
     */
    private function formatQuotationForResponse(array $quotation, ?array $customer = null): array
    {
        if ($customer === null) {
            $customer = [
                'id' => $quotation['customer_ref_id'] ?? $quotation['customer_id'] ?? null,
                'name' => $quotation['customer_name'] ?? null,
                'email' => $quotation['customer_email'] ?? null,
                'phone' => $quotation['customer_phone'] ?? null,
                'company' => $quotation['customer_company'] ?? null,
            ];
        }

        $quotation['customer'] = [
            'id' => (int) ($customer['id'] ?? 0) ?: null,
            'name' => $this->normalizeNullableText($customer['name'] ?? null),
            'email' => $this->normalizeNullableText($customer['email'] ?? null),
            'phone' => $this->normalizeNullableText($customer['phone'] ?? null),
            'company' => $this->normalizeNullableText($customer['company'] ?? null),
        ];

        unset($quotation['title']);
        unset($quotation['customer_ref_id'], $quotation['customer_name'], $quotation['customer_email'], $quotation['customer_phone'], $quotation['customer_company']);

        return $quotation;
    }

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    private function paginateFormattedQuotations(?int $customerId, string $search, int $perPage, int $offset, ?string $status): array
    {
        $quotationModel = new QuotationModel();
        $result = $quotationModel->paginateQuotations($customerId, $search, $perPage, $offset, $status);
        $result['items'] = array_map(fn (array $quotation): array => $this->formatQuotationForResponse($quotation), $result['items']);

        return $result;
    }

    /**
     * @return string|array{error:string}|null
     */
    private function resolveStatusFilter(mixed $status)
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim((string) $status));
        if ($normalized === '') {
            return null;
        }

        if (!in_array($normalized, self::ALLOWED_STATUSES, true)) {
            return [
                'error' => 'Allowed values: ' . implode(', ', self::ALLOWED_STATUSES) . '.',
            ];
        }

        return $normalized;
    }

    private function normalizeDiscountScope(mixed $value): string
    {
        $discountScope = trim((string) $value);
        return $discountScope !== '' ? $discountScope : 'quotation_total';
    }

    private function normalizeMoneyValue(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function normalizeDecimalValue(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeDateString($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }



    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateQuotationPayload(array $data): array
    {
        $errors = [];

        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        if ($customerId < 1) {
            $errors['customer_id'] = 'Customer id is required.';
        }

        return $errors;
    }
}
