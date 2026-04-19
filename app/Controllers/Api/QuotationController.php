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
    public function store()
    {
        $data = $this->getRequestData(false);
        $errors = $this->validateQuotationPayload($data);
        if ($errors !== []) {
            return $this->res->validation($errors);
        }

        $quotationModel = new QuotationModel();
        $quoteNumber = $quotationModel->generateQuoteNumber();

        $payload = [
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'quote_number' => $quoteNumber,
            'title' => trim((string) ($data['title'] ?? 'Quotation')),
            'status' => trim((string) ($data['status'] ?? 'submitted')) ?: 'submitted',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'submitted_at' => date('Y-m-d H:i:s'),
        ];

        $quotationModel->insert($payload);

        return $this->res->created($quotationModel->find((int) $quotationModel->getInsertID()), 'Quotation created successfully');
    }

    public function submit()
    {
        $data = $this->normalizeIncomingPayload($this->getRequestData(false));
        $projectItems = $this->extractProjectItems($data);

        if ($projectItems === []) {
            return $this->res->badRequest('At least one project is required.', [
                'projects' => 'Provide project_title or a projects array with one or more items.',
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

        $clientName = trim((string) ($data['client_name'] ?? ''));
        $clientEmail = trim((string) ($data['client_email'] ?? ''));
        $clientPhone = trim((string) ($data['client_phone'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));
        $customerId = $this->resolveCustomerId($customerModel, $clientName, $clientEmail, $clientPhone, $company);

        $firstTitle = trim((string) ($projectItems[0]['project_title'] ?? ''));
        $title = $firstTitle !== '' ? $firstTitle : 'Quotation for ' . ($clientName !== '' ? $clientName : $clientEmail);
        $quoteNumber = $quotationModel->generateQuoteNumber();

        $quotationModel->insert([
            'customer_id' => $customerId,
            'quote_number' => $quoteNumber,
            'title' => $title,
            'status' => 'submitted',
            'notes' => null,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);

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
                'deadline_date' => $item['deadline_date'],
                'estimated_amount' => $item['estimated_amount'],
                'payment_type' => $item['payment_type'],
                'hourly_hours' => $item['hourly_hours'],
                'discount_type' => $item['discount_type'],
                'discount_value' => $item['discount_value'],
                'discount_scope' => $item['discount_scope'],
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

        $quotation['projects'] = $this->formatProjectsForResponse($createdProjects);
        $quotation['project_count'] = count($createdProjects);

        return $this->res->created($quotation, 'Quotation created successfully from projects.');
    }

    public function index()
    {
        $quotationModel = new QuotationModel();
        $customerId = (int) ($this->request->getGet('customer_id') ?? 0);

        $builder = $quotationModel
            ->select('quotations.*')
            ->orderBy('quotations.id', 'DESC');

        if ($customerId > 0) {
            $builder->where('quotations.customer_id', $customerId);
        }

        $quotations = $builder->findAll();

        return $this->res->ok($this->attachSummary($quotations), 'Quotations retrieved successfully');
    }

    public function show(int $id)
    {
        $quotationModel = new QuotationModel();
        $quotation = $quotationModel->find($id);
        if (!is_array($quotation)) {
            return $this->res->notFound('Quotation not found');
        }

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

        $quotationModel = new QuotationModel();
        $quotations = $quotationModel
            ->where('customer_id', $customerId)
            ->orderBy('id', 'DESC')
            ->findAll();

        return $this->res->ok($this->attachSummary($quotations), 'Customer quotations retrieved successfully');
    }

    /**
     * @param array<int, array<string, mixed>> $quotations
     * @return array<int, array<string, mixed>>
     */
    private function attachSummary(array $quotations): array
    {
        if ($quotations === []) {
            return [];
        }

        $ids = array_map(static fn (array $q): int => (int) ($q['id'] ?? 0), $quotations);
        $ids = array_values(array_filter($ids));
        if ($ids === []) {
            return $quotations;
        }

        $projectRows = model(ProjectModel::class)
            ->select('quotation_id, COUNT(*) AS total')
            ->whereIn('quotation_id', $ids)
            ->groupBy('quotation_id')
            ->findAll();

        $projectCountMap = [];
        foreach ($projectRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $projectCountMap[(int) ($row['quotation_id'] ?? 0)] = (int) ($row['total'] ?? 0);
        }

        foreach ($quotations as &$quotation) {
            $qid = (int) ($quotation['id'] ?? 0);
            $quotation['project_count'] = $projectCountMap[$qid] ?? 0;
        }
        unset($quotation);

        return $quotations;
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
            $project['discount_type'] = $project['discount_type'] ?? null;
            $project['discount_value'] = $project['discount_value'] ?? null;
            $project['discount_scope'] = (string) ($project['discount_scope'] ?? 'project_total');
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
            'discount_type' => $this->normalizeDiscountType($project['discount_type'] ?? ($project['discountType'] ?? null)),
            'discount_value' => $this->normalizeDecimalValue($project['discount_value'] ?? ($project['discountValue'] ?? null)),
            'discount_scope' => $this->normalizeDiscountScope($project['discount_scope'] ?? ($project['discountScope'] ?? null)),
            'scope' => trim((string) ($project['scope'] ?? '')),
            'estimate_type' => trim((string) ($project['estimate_type'] ?? ($project['estimateType'] ?? ''))),
            'zip_code' => trim((string) ($project['zip_code'] ?? ($project['zipCode'] ?? ''))),
            'deadline' => trim((string) ($project['deadline'] ?? '')),
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
        $errors = [];

        $name = trim((string) ($data['client_name'] ?? ''));
        $email = trim((string) ($data['client_email'] ?? ''));
        $phone = trim((string) ($data['client_phone'] ?? ''));

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            $errors['client_name'] = 'Client name is required and must be between 2 and 160 characters.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['client_email'] = 'A valid client email is required and must not exceed 190 characters.';
        }

        if ($phone !== '' && !preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            $errors['client_phone'] = 'Client phone must be in valid E.164 format (e.g. +14155552671).';
        }

        return $errors;
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     * @return array<string, string>
     */
    private function validateProjectItems(array $projectItems): array
    {
        $errors = [];

        foreach ($projectItems as $index => $item) {
            if ($item['project_title'] === '') {
                $errors['projects.' . $index . '.project_title'] = 'Project title is required.';
            }

            if (mb_strlen($item['project_title']) < 3 || mb_strlen($item['project_title']) > 190) {
                $errors['projects.' . $index . '.project_title'] = 'Project title must be between 3 and 190 characters.';
            }

            if ($item['deadline_date'] !== null && strtotime((string) $item['deadline_date']) === false) {
                $errors['projects.' . $index . '.deadlineDate'] = 'Deadline date must be a valid date.';
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

        $discountType = $project['discount_type'] ?? null;
        if ($discountType !== null && $discountType !== '' && !in_array($discountType, ['fixed_amount', 'percentage'], true)) {
            $errors['projects.' . $index . '.discount_type'] = 'Discount type must be fixed_amount or percentage.';
        }

        if ($discountType !== null && $discountType !== '' && (!is_numeric($project['discount_value'] ?? null) || (float) $project['discount_value'] <= 0)) {
            $errors['projects.' . $index . '.discount_value'] = 'Discount value must be greater than 0 when a discount type is provided.';
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
        return in_array($discountType, ['fixed_amount', 'percentage'], true) ? $discountType : null;
    }

    private function normalizeDiscountScope(mixed $value): string
    {
        $discountScope = trim((string) $value);
        return $discountScope !== '' ? $discountScope : 'project_total';
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

    private function resolveCustomerId(
        CustomerModel $customerModel,
        string $name,
        string $email,
        string $phone,
        string $company
    ): ?int {
        if ($email === '') {
            return null;
        }

        $existing = $customerModel->where('email', $email)->first();
        $payload = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone === '' ? null : $phone,
            'company' => $company === '' ? null : $company,
        ];

        if (is_array($existing)) {
            $customerId = (int) ($existing['id'] ?? 0);
            if ($customerId < 1) {
                return null;
            }

            $customerModel->update($customerId, $payload);

            return $customerId;
        }

        $customerModel->insert($payload);
        $insertId = (int) $customerModel->getInsertID();

        return $insertId > 0 ? $insertId : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function validateQuotationPayload(array $data): array
    {
        $errors = [];

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) < 2 || mb_strlen($title) > 190) {
            $errors['title'] = 'Quotation title is required and must be between 2 and 190 characters.';
        }

        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        if ($customerId < 1) {
            $errors['customer_id'] = 'Customer id is required.';
        }

        return $errors;
    }
}
