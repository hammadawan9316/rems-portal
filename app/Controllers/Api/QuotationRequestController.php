<?php

namespace App\Controllers\Api;

use App\Models\CategoryModel;
use App\Models\ProjectFileModel;
use App\Models\ProjectModel;
use App\Models\ProjectServiceModel;
use App\Models\QuotationModel;
use App\Models\QuotationContractModel;
use App\Models\QuotationRequestModel;
use App\Models\QuotationRequestProjectModel;
use App\Models\ServiceModel;
use Config\Database;

class QuotationRequestController extends BaseApiController
{
    public function index()
    {
        $params = $this->getListQueryParams();

        $page       = $params['page']       ?? 1;
        $perPage    = $params['perPage']    ?? 10;
        $sortBy     = $params['sort_by']    ?? 'id';
        $sortOrder  = $params['sort_order'] ?? 'DESC';
        $search     = $params['search']     ?? null;

        $requests = (new QuotationRequestModel())
            ->getAllQuotationRequests($page, $perPage, $sortBy, $sortOrder, $search);

        return $this->res->paginated($requests['items'], $requests['total'], $page, $perPage, 'Quotation requests retrieved successfully');
    }
    public function show(int $id)
    {
        $requestModel = new QuotationRequestModel();
        $requestProjectModel = new QuotationRequestProjectModel();
        $projectFileModel = new ProjectFileModel();
        $categoryModel = new CategoryModel();
        $serviceModel = new ServiceModel();
        $quotationModel = new QuotationModel();
        $quotationContractModel = new QuotationContractModel();

        $request = $requestModel->find($id);
        if (!is_array($request)) {
            return $this->res->notFound('Quotation request not found');
        }

        unset($request['payload_snapshot']);

        $quotation = $quotationModel->where('source_request_id', $id)->orderBy('id', 'DESC')->first();
        $quotationId = is_array($quotation) ? (int) ($quotation['id'] ?? 0) : 0;
        $contractId = null;

        if ($quotationId > 0) {
            $quotationContract = $quotationContractModel->findByQuotationId($quotationId);
            $resolvedContractId = is_array($quotationContract) ? (int) ($quotationContract['contract_id'] ?? 0) : 0;
            $contractId = $resolvedContractId > 0 ? $resolvedContractId : null;
        }

        $projects = $requestProjectModel
            ->where('quotation_request_id', $id)
            ->orderBy('request_project_index', 'ASC')
            ->findAll();

        $categoryIds = [];
        $allServiceIds = [];
        $serviceIdsByRequestProjectIndex = [];

        foreach ($projects as $projectRow) {
            if (!is_array($projectRow)) {
                continue;
            }

            $categoryId = (int) ($projectRow['category_id'] ?? 0);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }

            $rawServiceIds = json_decode((string) ($projectRow['service_ids_json'] ?? '[]'), true);
            $normalizedServiceIds = is_array($rawServiceIds)
                ? array_values(array_unique(array_filter(array_map('intval', $rawServiceIds), static fn (int $serviceId): bool => $serviceId > 0)))
                : [];

            $requestProjectIndex = (int) ($projectRow['request_project_index'] ?? 0);
            $serviceIdsByRequestProjectIndex[$requestProjectIndex] = $normalizedServiceIds;
            $allServiceIds = array_merge($allServiceIds, $normalizedServiceIds);
        }

        $categoryIds = array_values(array_unique($categoryIds));
        $allServiceIds = array_values(array_unique($allServiceIds));

        $categoryNameById = [];
        if ($categoryIds !== []) {
            $categoryRows = $categoryModel->whereIn('id', $categoryIds)->findAll();
            foreach ($categoryRows as $categoryRow) {
                if (!is_array($categoryRow)) {
                    continue;
                }

                $categoryId = (int) ($categoryRow['id'] ?? 0);
                if ($categoryId > 0) {
                    $categoryNameById[$categoryId] = trim((string) ($categoryRow['name'] ?? ''));
                }
            }
        }

        $serviceNameById = [];
        if ($allServiceIds !== []) {
            $serviceRows = $serviceModel->whereIn('id', $allServiceIds)->findAll();
            foreach ($serviceRows as $serviceRow) {
                if (!is_array($serviceRow)) {
                    continue;
                }

                $serviceId = (int) ($serviceRow['id'] ?? 0);
                if ($serviceId > 0) {
                    $serviceNameById[$serviceId] = trim((string) ($serviceRow['name'] ?? ''));
                }
            }
        }

        foreach ($projects as &$project) {
            if (!is_array($project)) {
                continue;
            }

            unset($project['raw_payload']);

            $requestProjectIndex = (int) ($project['request_project_index'] ?? 0);
            $serviceIds = $serviceIdsByRequestProjectIndex[$requestProjectIndex] ?? [];
            $project['service_ids'] = $serviceIds;
            $project['services'] = array_values(array_filter(array_map(static fn (int $serviceId): string => $serviceNameById[$serviceId] ?? '', $serviceIds), static fn (string $name): bool => $name !== ''));

            $categoryId = (int) ($project['category_id'] ?? 0);
            $project['category'] = $categoryNameById[$categoryId] ?? '';

            $files = $projectFileModel
                ->where('quotation_request_id', $id)
                ->where('request_project_index', $requestProjectIndex)
                ->orderBy('id', 'ASC')
                ->findAll();

            $project['files'] = array_values(array_map(static function (array $file): array {
                return [
                    'id' => (int) ($file['id'] ?? 0),
                    'access_token' => (string) ($file['public_token'] ?? ''),
                ];
            }, array_values(array_filter($files, static fn ($file): bool => is_array($file)))));
        }
        unset($project);

        $request['quotation_id'] = $quotationId > 0 ? $quotationId : null;
        $request['contract_id'] = $contractId;
        $request['projects'] = $projects;
        $request['project_count'] = count($projects);

        return $this->res->ok($request, 'Quotation request retrieved successfully');
    }

    public function createQuotation(int $id)
    {
        $requestModel = new QuotationRequestModel();
        $requestProjectModel = new QuotationRequestProjectModel();
        $quotationModel = new QuotationModel();
        $projectModel = new ProjectModel();
        $projectServiceModel = new ProjectServiceModel();
        $projectFileModel = new ProjectFileModel();

        $request = $requestModel->find($id);
        if (!is_array($request)) {
            return $this->res->notFound('Quotation request not found');
        }

        $existingQuotation = $quotationModel->where('source_request_id', $id)->first();
        if (is_array($existingQuotation)) {
            return $this->res->ok([
                'quotation_id' => (int) ($existingQuotation['id'] ?? 0),
                'source_request_id' => $id,
            ], 'Quotation already exists for this request.');
        }

        $status = trim((string) ($request['status'] ?? 'requested'));
        if ($status !== 'requested') {
            return $this->res->badRequest('Only requested records can be converted into quotations.', [
                'status' => 'Current status: ' . $status,
            ]);
        }

        $requestProjects = $requestProjectModel
            ->where('quotation_request_id', $id)
            ->orderBy('request_project_index', 'ASC')
            ->findAll();

        if ($requestProjects === []) {
            return $this->res->badRequest('Cannot create quotation without request projects.');
        }

        $db = Database::connect();
        $db->transStart();

        $quotationModel->insert([
            'customer_id' => (int) ($request['customer_id'] ?? 0) ?: null,
            'source_request_id' => $id,
            'quote_number' => $quotationModel->generateQuoteNumber(),
            'description' => (string) ($request['description'] ?? '') !== '' ? (string) $request['description'] : null,
            'status' => 'pending',
            'notes' => (string) ($request['notes'] ?? '') !== '' ? (string) $request['notes'] : null,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        $quotationId = (int) $quotationModel->getInsertID();

        $createdProjectIds = [];
        foreach ($requestProjects as $requestProject) {
            if (!is_array($requestProject)) {
                continue;
            }

            $projectModel->insert([
                'customer_id' => (int) ($request['customer_id'] ?? 0) ?: null,
                'quotation_id' => $quotationId,
                'category_id' => (int) ($requestProject['category_id'] ?? 0) ?: null,
                'project_title' => (string) ($requestProject['project_title'] ?? ''),
                'project_description' => (string) ($requestProject['project_description'] ?? ''),
                'scope' => (string) ($requestProject['scope'] ?? ''),
                'estimate_type' => (string) ($requestProject['estimate_type'] ?? ''),
                'plans_url' => (string) ($requestProject['plans_url'] ?? ''),
                'zip_code' => (string) ($requestProject['zip_code'] ?? ''),
                'deadline' => (string) ($requestProject['deadline'] ?? ''),
                'delivery_date' => (string) ($requestProject['delivery_date'] ?? ''),
                'deadline_date' => (string) ($requestProject['deadline_date'] ?? ''),
                'estimated_amount' => $requestProject['estimated_amount'] ?? null,
                'payment_type' => (string) ($requestProject['payment_type'] ?? 'fixed_rate'),
                'hourly_hours' => $requestProject['hourly_hours'] ?? null,
                'status' => 'submitted',
            ]);

            $projectId = (int) $projectModel->getInsertID();
            $createdProjectIds[] = $projectId;

            $serviceIds = json_decode((string) ($requestProject['service_ids_json'] ?? '[]'), true);
            $projectServiceModel->replaceServices($projectId, is_array($serviceIds) ? $serviceIds : []);

            $requestProjectIndex = (int) ($requestProject['request_project_index'] ?? 0);
            $projectFileModel
                ->where('quotation_request_id', $id)
                ->where('request_project_index', $requestProjectIndex)
                ->set([
                    'project_id' => $projectId,
                ])
                ->update();
        }

        $requestModel->update($id, [
            'status' => 'quoted',
            'quoted_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->res->serverError('Could not create quotation from request.');
        }

        return $this->res->created([
            'source_request_id' => $id,
            'quotation_id' => $quotationId,
            'project_ids' => $createdProjectIds,
            'project_count' => count($createdProjectIds),
        ], 'Quotation created successfully from request.');
    }
}
