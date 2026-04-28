<?php

namespace App\Controllers\Api;

use App\Libraries\JwtService;
use App\Models\CategoryModel;
use App\Models\CustomerModel;
use App\Models\ProjectFileModel;
use App\Models\ProjectModel;
use App\Models\ProjectServiceModel;
use App\Models\QuotationRequestModel;
use App\Models\QuotationRequestProjectModel;
use App\Models\ServiceModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Square;

class ProjectIntakeController extends BaseApiController
{
    private const REQUEST_TOTAL_UPLOAD_LIMIT_KB = 102400;
    private const FILE_UPLOAD_LIMIT_KB = 512000;

    public function submit()
    {
        $data = $this->normalizeIncomingPayload($this->getRequestData(false));
        $rawProjectItems = $this->extractProjectItems($data);
        $projectItems = $rawProjectItems;
        $projectCount = count($projectItems);

        if ($projectItems === []) {
            return $this->res->badRequest('At least one project is required.', [
                'projects' => 'Provide a projects array with one or more items, or the new payload format.',
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

        $uploadedFilesResult = $this->storeUploadedFilesByProject($projectCount);
        if (isset($uploadedFilesResult['error'])) {
            return $this->res->badRequest('File upload failed.', ['files' => $uploadedFilesResult['error']]);
        }
        $uploadedFilesByProject = $uploadedFilesResult['by_project'] ?? [];
        $uploadedFiles = $uploadedFilesResult['all'] ?? [];

        $customerModel = new CustomerModel();
        $quotationRequestModel = new QuotationRequestModel();
        $quotationRequestProjectModel = new QuotationRequestProjectModel();
        $projectFileModel = new ProjectFileModel();

        $clientName = trim((string) ($data['client_name'] ?? ''));
        $clientEmail = trim((string) ($data['client_email'] ?? ''));
        $clientPhone = trim((string) ($data['client_phone'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));
        $customerId = $this->resolveCustomerId($customerModel, $clientName, $clientEmail, $clientPhone, $company);

        $requestId = $this->createQuotationRequest(
            $quotationRequestModel,
            $customerId,
            $data,
            [
                'request' => $data,
                'projects' => $projectItems,
            ]
        );

        $requestRow = $requestId !== null ? $quotationRequestModel->find($requestId) : null;
        $requestNumber = is_array($requestRow) ? trim((string) ($requestRow['request_number'] ?? '')) : '';

        if ($requestId === null) {
            return $this->res->serverError('Quotation request could not be created.');
        }

        $createdRequestProjects = [];
        $squareResults = [];
        $secureFiles = [];
        $projectsByFile = [];

        foreach ($projectItems as $index => $item) {
            $requestProjectData = [
                'quotation_request_id' => $requestId,
                'request_project_index' => $index,
                'category_id' => (int) ($item['category_id'] ?? 0) ?: null,
                'project_title' => $item['project_title'],
                'project_description' => $item['project_description'],
                'scope' => $item['scope'],
                'estimate_type' => $item['estimate_type'],
                'plans_url' => $item['plans_url'],
                'zip_code' => $item['zip_code'],
                'deadline' => $item['deadline'],
                'delivery_date' => $item['delivery_date'],
                'deadline_date' => $item['deadline_date'],
                'estimated_amount' => $item['estimated_amount'],
                'payment_type' => $item['payment_type'],
                'hourly_hours' => $item['hourly_hours'],
                'service_ids_json' => json_encode(is_array($item['service_ids'] ?? null) ? $item['service_ids'] : [], JSON_UNESCAPED_SLASHES),
                'raw_payload' => json_encode($rawProjectItems[$index] ?? $item, JSON_UNESCAPED_SLASHES),
            ];

            $quotationRequestProjectModel->insert($requestProjectData);
            $requestProjectId = (int) $quotationRequestProjectModel->getInsertID();

            $projectUploadedFiles = [];
            if (isset($uploadedFilesByProject[$index]) && is_array($uploadedFilesByProject[$index])) {
                $projectUploadedFiles = $uploadedFilesByProject[$index];
            }

            $projectSecureFiles = $this->attachProjectFiles(
                $projectFileModel,
                null,
                $projectUploadedFiles,
                $requestId,
                $index
            );
            $secureFiles = array_merge($secureFiles, $projectSecureFiles);
            $projectsByFile[$index] = $projectSecureFiles;

            $savedRequestProject = $quotationRequestProjectModel->find($requestProjectId);
            if (is_array($savedRequestProject)) {
                $savedRequestProject['category'] = (string) ($item['category'] ?? '');
                $savedRequestProject['services'] = is_array($item['services'] ?? null) ? $item['services'] : [];
                $savedRequestProject['service_ids'] = is_array($item['service_ids'] ?? null) ? $item['service_ids'] : [];
                $createdRequestProjects[] = $savedRequestProject;
            }

            $squareResults[] = [
                'request_project_id' => $requestProjectId,
                'status' => 'deferred',
            ];
        }

        $this->queueOwnerNotificationForRequest(
            $requestId,
            $requestNumber,
            $createdRequestProjects,
            $projectsByFile,
            $clientName,
            $clientEmail,
            $squareResults
        );

        $this->queueCustomerSubmittedNotification($clientEmail, $clientName, $requestNumber);

        $isMultiple = count($projectItems) > 1;
        $singleProject = $createdRequestProjects[0] ?? null;
        $singleSquare = $squareResults[0] ?? null;

        return $this->res->created([
            'request_id' => $requestId,
            'request_number' => $requestNumber,
            'quotation_id' => null,
            'project' => $singleProject,
            'square' => $singleSquare,
            'projects' => $createdRequestProjects,
            'square_results' => $squareResults,
            'project_count' => count($createdRequestProjects),
            'multiple_projects' => $isMultiple,
            'files' => $uploadedFiles,
            'files_by_project' => $uploadedFilesByProject,
            'secure_files' => $secureFiles,
            'sequence' => [
                'request_created' => true,
                'quotation_created' => false,
                'square_processing' => 'deferred_until_admin_action',
            ],
        ], 'Quotation request submitted successfully. Admin quotation and Square sync are deferred.');
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     */
    private function createQuotationRequest(
        QuotationRequestModel $quotationRequestModel,
        ?int $customerId,
        array $data,
        array $snapshot
    ): ?int {
        $requestNumber = $quotationRequestModel->generateRequestNumber();
        $description = trim((string) ($data['description'] ?? ($data['title'] ?? '')));
        $notes = trim((string) ($data['notes'] ?? ''));

        $quotationRequestModel->insert([
            'customer_id' => $customerId,
            'request_number' => $requestNumber,
            'client_name' => trim((string) ($data['client_name'] ?? '')),
            'client_email' => trim((string) ($data['client_email'] ?? '')),
            'client_phone' => trim((string) ($data['client_phone'] ?? '')),
            'company' => trim((string) ($data['company'] ?? '')),
            'description' => $description !== '' ? $description : null,
            'status' => 'requested',
            'notes' => $notes !== '' ? $notes : null,
            'payload_snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
        ]);

        $id = (int) $quotationRequestModel->getInsertID();

        return $id > 0 ? $id : null;
    }

    public function downloadFile(string $token)
    {
        $projectFileModel = new ProjectFileModel();
        $file = $projectFileModel->where('public_token', trim($token))->first();

        if (!is_array($file)) {
            return $this->res->notFound('File was not found.');
        }

        $passwordHash = (string) ($file['access_password_hash'] ?? '');
        $isAdmin = is_admin();
        if (!$isAdmin && $passwordHash !== '') {
            $password = trim((string) ($this->request->getHeaderLine('X-File-Password') ?: $this->request->getGet('password')));
            if ($password === '' || !password_verify($password, $passwordHash)) {
                return $this->res->unauthorized('Invalid or missing file password.');
            }
        }

        $fullPath = (string) ($file['full_path'] ?? '');
        if ($fullPath === '' || !is_file($fullPath)) {
            return $this->res->notFound('Stored file does not exist on disk.');
        }

        return $this->response->download($fullPath, null)->setFileName((string) ($file['original_name'] ?? basename($fullPath)));
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

                $items[] = [
                    'project_title' => '',
                    'project_description' => trim((string) ($project['project_description'] ?? ($project['scope'] ?? ''))),
                    'estimated_amount' => $this->normalizeMoneyValue($project['estimated_amount'] ?? ($project['amount'] ?? null)),
                    'category_id' => $this->normalizeCategoryId($project),
                    'service_ids' => $this->normalizeServiceIds($project['services'] ?? ($project['service_ids'] ?? [])),
                    'payment_type' => $this->normalizePaymentType($project['payment_type'] ?? ($project['paymentType'] ?? 'fixed_rate')),
                    'hourly_hours' => $this->normalizeDecimalValue($project['hourly_hours'] ?? ($project['hours'] ?? null)),
                    'scope' => trim((string) ($project['scope'] ?? '')),
                    'estimate_type' => trim((string) ($project['estimateType'] ?? ($project['estimate_type'] ?? ''))),
                    'plans_url' => trim((string) ($project['plansUrl'] ?? ($project['plans_url'] ?? ''))),
                    'zip_code' => trim((string) ($project['zipCode'] ?? ($project['zip_code'] ?? ''))),
                    'deadline' => trim((string) ($project['deadline'] ?? '')),
                    'delivery_date' => $this->normalizeDateString($project['delivery_date'] ?? ($project['deliveryDate'] ?? null)),
                    'deadline_date' => $this->normalizeDateString($project['deadline_date'] ?? ($project['deadlineDate'] ?? null)),
                ];
            }

            return $items;
        }

        $singleTitle = trim((string) ($data['project_title'] ?? ''));
        $items[] = [
            'project_title' => $singleTitle,
            'project_description' => trim((string) ($data['project_description'] ?? '')),
            'estimated_amount' => $this->normalizeMoneyValue($data['estimated_amount'] ?? ($data['amount'] ?? null)),
            'category_id' => $this->normalizeCategoryId($data),
            'service_ids' => $this->normalizeServiceIds($data['services'] ?? ($data['service_ids'] ?? [])),
            'payment_type' => $this->normalizePaymentType($data['payment_type'] ?? ($data['paymentType'] ?? 'fixed_rate')),
            'hourly_hours' => $this->normalizeDecimalValue($data['hourly_hours'] ?? ($data['hours'] ?? null)),
            'scope' => trim((string) ($data['scope'] ?? '')),
            'estimate_type' => trim((string) ($data['estimateType'] ?? ($data['estimate_type'] ?? ''))),
            'plans_url' => trim((string) ($data['plansUrl'] ?? ($data['plans_url'] ?? ''))),
            'zip_code' => trim((string) ($data['zipCode'] ?? ($data['zip_code'] ?? ''))),
            'deadline' => trim((string) ($data['deadline'] ?? '')),
            'delivery_date' => $this->normalizeDateString($data['delivery_date'] ?? ($data['deliveryDate'] ?? null)),
            'deadline_date' => $this->normalizeDateString($data['deadline_date'] ?? ($data['deadlineDate'] ?? null)),
        ];

        return $items;
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

        if ($phone !== '' && !$this->isValidE164Phone($phone)) {
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
            if ($item['plans_url'] !== '' && !$this->isValidPlansUrl($item['plans_url'])) {
                $errors['projects.' . $index . '.plansUrl'] = 'Plans URL must be a valid URL.';
            }

            $billingErrors = $this->validateProjectBillingItem($item, $index);
            foreach ($billingErrors as $field => $message) {
                $errors[$field] = $message;
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, string>
     */
    private function validateProjectBillingItem(array $item, int $index): array
    {
        $errors = [];

        $paymentType = strtolower(trim((string) ($item['payment_type'] ?? 'fixed_rate')));
        if (!in_array($paymentType, ['fixed_rate', 'hourly'], true)) {
            $errors['projects.' . $index . '.payment_type'] = 'Payment type must be fixed_rate or hourly.';
        }

        $hours = $item['hourly_hours'] ?? null;
        if ($paymentType === 'hourly') {
            if (!is_numeric($hours) || (float) $hours <= 0) {
                $errors['projects.' . $index . '.hourly_hours'] = 'Hourly payment requires a valid hours value greater than 0.';
            }
        } elseif ($hours !== null && $hours !== '' && !is_numeric($hours)) {
            $errors['projects.' . $index . '.hourly_hours'] = 'Hours must be a valid number.';
        }

        $amount = $item['estimated_amount'] ?? null;
        if ($amount !== null && $amount !== '' && (!is_numeric($amount) || (float) $amount < 0)) {
            $errors['projects.' . $index . '.estimated_amount'] = 'Amount must be a valid number.';
        }

        return $errors;
    }

    /**
     * @param mixed $value
     */
    private function normalizeMoneyValue($value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * @param mixed $value
     */
    private function normalizeDecimalValue($value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
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

    /**
     * @return array<int, array<string, mixed>>|array{error:string}
     */
    private function storeUploadedFilesByProject(int $projectCount): array
    {
        $emptyByProject = [];
        for ($i = 0; $i < $projectCount; $i++) {
            $emptyByProject[$i] = [];
        }

        $allFiles = $this->request->getFiles();
        if ($allFiles === []) {
            return [
                'all' => [],
                'by_project' => $emptyByProject,
            ];
        }

        $byProject = $emptyByProject;
        $uploadedAll = [];
        $directory = 'projects/' . date('Y') . '/' . date('m');
        $uploadPlan = [];
        $totalUploadSizeKb = 0.0;
        $projectFileBatches = [];
        $legacyFlatFiles = [];

        $projectGroups = $allFiles['projects'] ?? null;
        if (is_array($projectGroups)) {
            foreach ($projectGroups as $index => $projectGroup) {
                if (!is_array($projectGroup) || !array_key_exists('files', $projectGroup)) {
                    continue;
                }

                $flatProjectFiles = [];
                $this->flattenFiles(['files' => $projectGroup['files']], $flatProjectFiles);
                if ($flatProjectFiles === []) {
                    continue;
                }

                $projectSizeKb = $this->sumUploadedFileSizeKb($flatProjectFiles);
                $totalUploadSizeKb += $projectSizeKb;
                $projectFileBatches[(int) $index] = $flatProjectFiles;
                $uploadPlan[] = [
                    'label' => 'Project ' . ((int) $index + 1),
                    'size_kb' => $projectSizeKb,
                    'count' => count($flatProjectFiles),
                ];
            }
        }

        $legacyFiles = $allFiles['files'] ?? null;
        if ($legacyFiles !== null) {
            $this->flattenFiles(['files' => $legacyFiles], $legacyFlatFiles);
            if ($legacyFlatFiles !== []) {
                $legacySizeKb = $this->sumUploadedFileSizeKb($legacyFlatFiles);
                $totalUploadSizeKb += $legacySizeKb;
                $uploadPlan[] = [
                    'label' => 'Legacy files',
                    'size_kb' => $legacySizeKb,
                    'count' => count($legacyFlatFiles),
                ];
            }
        }

        if ($totalUploadSizeKb > self::REQUEST_TOTAL_UPLOAD_LIMIT_KB) {
            return ['error' => $this->buildUploadTotalLimitError($uploadPlan, $totalUploadSizeKb)];
        }

        foreach ($projectFileBatches as $index => $flatProjectFiles) {
            $result = $this->uploadService->uploadMany($flatProjectFiles, $directory, [], self::FILE_UPLOAD_LIMIT_KB);
            $uploaded = is_array($result['data'] ?? null) ? $result['data'] : [];

            if (($result['status'] ?? false) !== true && $uploaded === []) {
                return ['error' => $this->buildUploadFailureMessage('project ' . ((int) $index + 1), $flatProjectFiles, $result)];
            }

            if (!array_key_exists($index, $byProject)) {
                $byProject[(int) $index] = [];
            }
            $byProject[(int) $index] = array_values(array_merge($byProject[(int) $index], $uploaded));
            $uploadedAll = array_values(array_merge($uploadedAll, $uploaded));
        }

        if ($legacyFlatFiles !== []) {
            if ($projectCount > 1 && $uploadedAll === []) {
                return ['error' => 'For multiple projects use project-specific file fields: projects[index][files][].'];
            }

            $result = $this->uploadService->uploadMany($legacyFlatFiles, $directory, [], self::FILE_UPLOAD_LIMIT_KB);
            $uploaded = is_array($result['data'] ?? null) ? $result['data'] : [];

            if (($result['status'] ?? false) !== true && $uploaded === []) {
                return ['error' => $this->buildUploadFailureMessage('legacy files', $legacyFlatFiles, $result)];
            }

            $targetIndex = 0;
            if (!array_key_exists($targetIndex, $byProject)) {
                $byProject[$targetIndex] = [];
            }

            $byProject[$targetIndex] = array_values(array_merge($byProject[$targetIndex], $uploaded));
            $uploadedAll = array_values(array_merge($uploadedAll, $uploaded));
        }

        return [
            'all' => $uploadedAll,
            'by_project' => $byProject,
        ];
    }

    /**
     * @param array<string, mixed> $files
     * @param array<int, UploadedFile> $bucket
     */
    private function flattenFiles(array $files, array &$bucket): void
    {
        foreach ($files as $item) {
            if ($item instanceof UploadedFile) {
                $bucket[] = $item;
                continue;
            }

            if (is_array($item)) {
                $this->flattenFiles($item, $bucket);
            }
        }
    }

    /**
     * @param array<int, UploadedFile> $files
     */
    private function sumUploadedFileSizeKb(array $files): float
    {
        $total = 0.0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $total += (float) $file->getSizeByUnit('kb');
        }

        return round($total, 2);
    }

    /**
     * @param array<int, UploadedFile> $files
     * @param array{status:bool,message:string,data?:array,errors?:array} $result
     */
    private function buildUploadFailureMessage(string $contextLabel, array $files, array $result): string
    {
        $details = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $sizeKb = (float) $file->getSizeByUnit('kb');
            $details[] = $file->getClientName() . ' (' . $this->formatUploadSizeKb($sizeKb) . ')';
        }

        $errorMessages = [];
        $uploadErrors = $result['errors'] ?? [];
        foreach ($uploadErrors as $error) {
            if (is_array($error['errors'] ?? null)) {
                foreach ($error['errors'] as $fieldError) {
                    $errorMessages[] = (string) $fieldError;
                }
            } elseif (isset($error['message'])) {
                $errorMessages[] = (string) $error['message'];
            }
        }

        $reason = $errorMessages !== [] ? implode(' | ', $errorMessages) : 'Upload failed.';
        $fileList = $details !== [] ? ' Files: ' . implode(', ', $details) . '.' : '';

        return 'Unable to store uploaded files for ' . $contextLabel . ': ' . $reason . $fileList;
    }

    /**
     * @param array<int, array{label:string,size_kb:float,count:int}> $uploadPlan
     */
    private function buildUploadTotalLimitError(array $uploadPlan, float $totalUploadSizeKb): string
    {
        $parts = [];
        foreach ($uploadPlan as $entry) {
            $label = (string) ($entry['label'] ?? 'Files');
            $sizeKb = (float) ($entry['size_kb'] ?? 0);
            $count = (int) ($entry['count'] ?? 0);

            $parts[] = $label . ': ' . $this->formatUploadSizeKb($sizeKb) . ' across ' . $count . ' file(s)';
        }

        $limitMb = $this->formatUploadSizeKb(self::REQUEST_TOTAL_UPLOAD_LIMIT_KB);
        return 'Total uploaded files exceed the request limit of ' . $limitMb . '. Current total is ' . $this->formatUploadSizeKb($totalUploadSizeKb) . '.' . ($parts !== [] ? ' Breakdown: ' . implode('; ', $parts) . '.' : '');
    }

    private function formatUploadSizeKb(float $sizeKb): string
    {
        return number_format($sizeKb / 1024, 2) . 'MB';
    }

    /**
     * Send request-based notification to owner/admin with all projects
     *
     * @param int|null $requestId
     * @param string $requestNumber
     * @param array<int, array<string, mixed>> $projects
     * @param array<int, array<int, array<string, mixed>>> $projectsByFile
     * @param string $clientName
     * @param string $clientEmail
     * @param array<int, array<string, mixed>> $squareResults
     */
    private function queueOwnerNotificationForRequest(
        ?int $requestId,
        string $requestNumber,
        array $projects,
        array $projectsByFile,
        string $clientName,
        string $clientEmail,
        array $squareResults
    ): void {
        /** @var Square $squareConfig */
        $squareConfig = config('Square');
        $to = trim($squareConfig->ownerNotificationEmail);
        if ($to === '' || $requestId === null) {
            return;
        }

        $projectCount = count($projects);

        $contentParts = [
            '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">A new quotation request with ' . esc((string) $projectCount) . ' project(s) was submitted from the website.</p>',

            // Request Header
            '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;border-collapse:collapse;">',
            '<tr><td style="padding:12px;background:#0f172a;"><strong style="color:#ffffff;">Request Details</strong></td></tr>',
            '<tr><td style="padding:12px;">',
            ($requestNumber !== '' ? '<strong>Request Number:</strong> ' . esc($requestNumber) . '<br>' : ''),
            '<strong>Request ID:</strong> #' . esc((string) $requestId) . '<br>',
            '<strong>Total Projects:</strong> ' . esc((string) $projectCount) . '<br>',
            '<strong>Submitted:</strong> ' . date('F j, Y \a\t g:i A') . '<br>',
            '</td></tr>',
            '</table>',

            // Client Information
            '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;border-collapse:collapse;">',
            '<tr><td style="padding:12px;background:#f8fafc;border-left:3px solid #0f172a;"><strong style="color:#0f172a;">Client Information</strong></td></tr>',
            '<tr><td style="padding:12px;">',
            '<strong>Name:</strong> ' . esc($clientName) . '<br>',
            '<strong>Email:</strong> ' . esc($clientEmail) . '<br>',
            '</td></tr>',
            '</table>',
        ];

        // Build projects sections
        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = (int) ($project['id'] ?? 0);
            $projectTitle = (string) ($project['project_title'] ?? '');
            $projectDescription = (string) ($project['project_description'] ?? '');
            $projectCategory = (string) ($project['category'] ?? 'N/A');
            $projectServices = $this->normalizeStringList($project['services'] ?? []);
            $scope = (string) ($project['scope'] ?? '');
            $estimateType = (string) ($project['estimate_type'] ?? '');
            $plansUrl = (string) ($project['plans_url'] ?? '');
            $zipCode = (string) ($project['zip_code'] ?? '');
            $deadline = (string) ($project['deadline'] ?? '');
            $deliveryDate = (string) ($project['delivery_date'] ?? '');
            $deadlineDate = (string) ($project['deadline_date'] ?? '');
            $estimatedAmount = (string) ($project['estimated_amount'] ?? '');
            $paymentType = (string) ($project['payment_type'] ?? 'fixed_rate');
            $hourlyHours = (string) ($project['hourly_hours'] ?? '');

            // Project card
            $contentParts[] = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;border-collapse:collapse;">';
            $contentParts[] = '<tr><td style="padding:12px;background:#0f172a;"><strong style="color:#ffffff;">Request Project #' . esc((string) ($projectId > 0 ? $projectId : ((int) ($project['request_project_index'] ?? 0)))) . ': ' . esc($projectTitle) . '</strong></td></tr>';
            $contentParts[] = '<tr><td style="padding:12px;">';

            // Project basic info
            $contentParts[] = '<p style="margin:0 0 8px 0;"><strong>Category:</strong> ' . esc($projectCategory) . '</p>';
            $contentParts[] = '<p style="margin:0 0 8px 0;"><strong>Services:</strong> ' . esc($projectServices === [] ? 'N/A' : implode(', ', $projectServices)) . '</p>';

            // Description if available
            if ($projectDescription !== '') {
                $contentParts[] = '<p style="margin:8px 0;"><strong>Description:</strong></p>';
                $contentParts[] = '<div style="margin:4px 0;padding:8px;background:#f8fafc;border-left:2px solid #0f172a;font-size:14px;">' . nl2br(esc($projectDescription)) . '</div>';
            }

            // Project Details
            if ($scope !== '' || $estimateType !== '' || $plansUrl !== '' || $zipCode !== '' || $deadline !== '' || $deliveryDate !== '' || $deadlineDate !== '') {
                $contentParts[] = '<p style="margin:12px 0 8px 0;"><strong style="color:#0f172a;">Project Details</strong></p>';
                if ($scope !== '') {
                    $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Scope:</strong> ' . esc($scope) . '</p>';
                }
                if ($estimateType !== '') {
                    $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Estimate Type:</strong> ' . esc($estimateType) . '</p>';
                }
                if ($plansUrl !== '') {
                    $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Plans URL:</strong> <a href="' . esc($plansUrl) . '" style="color:#0f172a;text-decoration:none;">' . esc($plansUrl) . '</a></p>';
                }
                if ($zipCode !== '') {
                    $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Zip Code:</strong> ' . esc($zipCode) . '</p>';
                }
                if ($deadline !== '') {
                    $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Bid Deadline:</strong> ' . esc($deadline) . '</p>';
                }
                if ($deliveryDate !== '') {
                    $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Delivery Date:</strong> ' . esc($deliveryDate) . '</p>';
                }
                if ($deadlineDate !== '') {
                    $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Deadline Date:</strong> ' . esc($deadlineDate) . '</p>';
                }
            }

            // Billing Information
            $contentParts[] = '<p style="margin:12px 0 8px 0;"><strong style="color:#0f172a;">Billing Information</strong></p>';
            $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Payment Type:</strong> ' . esc($paymentType === 'hourly' ? 'Hourly' : 'Fixed Rate') . '</p>';
            if ($estimatedAmount !== '' && $estimatedAmount !== '0.00') {
                $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Estimated Amount:</strong> $' . esc($estimatedAmount) . '</p>';
            }
            if ($paymentType === 'hourly' && $hourlyHours !== '' && $hourlyHours !== '0.00') {
                $contentParts[] = '<p style="margin:4px 0;font-size:14px;"><strong>Hours:</strong> ' . esc($hourlyHours) . '</p>';
            }

            // Project files if any
            $projectIndex = (int) ($project['request_project_index'] ?? 0);
            $projectFiles = $projectsByFile[$projectIndex] ?? [];
            if ($projectFiles !== []) {
                $contentParts[] = '<p style="margin:12px 0 8px 0;"><strong style="color:#0f172a;">Uploaded Files (' . count($projectFiles) . ')</strong></p>';
                foreach ($projectFiles as $file) {
                    $fileName = (string) ($file['original_name'] ?? 'File');
                    $fileUrl = (string) ($file['download_url'] ?? '');
                    $filePassword = (string) ($file['password'] ?? '');
                    $fileSize = (int) ($file['size_kb'] ?? 0);

                    if ($fileUrl === '') {
                        continue;
                    }

                    $contentParts[] = '<div style="margin:8px 0;padding:8px;background:#f0f4f8;border-radius:4px;border-left:2px solid #0f172a;font-size:13px;">';
                    $contentParts[] = '<p style="margin:0 0 4px 0;"><strong>' . esc($fileName) . '</strong> (' . esc((string) $fileSize) . ' KB)</p>';
                    $contentParts[] = '<p style="margin:0 0 4px 0;"><a href="' . esc($fileUrl) . '" style="color:#0f172a;text-decoration:none;word-break:break-all;">Download</a></p>';
                    if ($filePassword !== '') {
                        $contentParts[] = '<p style="margin:0;"><strong>Pass:</strong> <code style="background:#ffffff;padding:2px 4px;border-radius:2px;font-family:monospace;border:1px solid #e5e7eb;">' . esc($filePassword) . '</code></p>';
                    }
                    $contentParts[] = '</div>';
                }
            }

            $contentParts[] = '</td></tr>';
            $contentParts[] = '</table>';
        }

        // Square Status Summary
        $squareStatuses = array_column($squareResults, 'status');
        $deferredCount = count(array_filter($squareStatuses, static fn($s) => $s === 'deferred'));

        $contentParts[] = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;border-collapse:collapse;">';
        $contentParts[] = '<tr><td style="padding:12px;background:#f8fafc;border-left:3px solid #0f172a;"><strong style="color:#0f172a;">Square Integration Status</strong></td></tr>';
        $contentParts[] = '<tr><td style="padding:12px;">';
        if ($deferredCount > 0) {
            $contentParts[] = '<p style="margin:0;"><strong>Deferred:</strong> <span style="background:#e0f2fe;padding:4px 8px;border-radius:4px;color:#075985;">' . esc((string) $deferredCount) . ' project(s) awaiting admin quotation/invoice action</span></p>';
        }
        $contentParts[] = '</td></tr>';
        $contentParts[] = '</table>';

        $emailQueue = service('emailQueue');
        $subject = 'New Quotation Request - ' . ($requestNumber !== '' ? $requestNumber : '#' . $requestId);
        $body = $emailQueue->renderTemplate([
            'subject' => $subject,
            'recipientName' => 'Admin',
            'headline' => 'New Quotation Request Received',
            'contentHtml' => implode('', $contentParts),
            'actionText' => 'Review in Dashboard',
            'actionUrl' => base_url('dashboard/quotation-requests/' . $requestId),
        ]);

        queue_email_job($to, $subject, $body, [
            'mail_type' => 'html',
        ]);
    }

    private function queueCustomerSubmittedNotification(
        string $email,
        string $name,
        ?int $requestNumber
    ): void {
        $to = trim($email);
        if ($to === '') {
            return;
        }

        $recipientName = $name === '' ? 'Customer' : $name;
        $contentHtml = '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">Thank you for your quotation request.</p>'
            . ($requestNumber !== null ? '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;"><strong>Request Number:</strong> #' . esc((string) $requestNumber) . '</p>' : '')
            . '<p style="margin:0;font-size:15px;line-height:1.6;">Our team will reach out to you within 3 hours.</p>';

        $emailQueue = service('emailQueue');
        $subject = 'Thank you for your request' . ($requestNumber !== null ? ' #' . $requestNumber : '');
        $body = $emailQueue->renderTemplate([
            'subject' => $subject,
            'recipientName' => $recipientName,
            'headline' => 'Thank You',
            'contentHtml' => $contentHtml,

        ]);

        queue_email_job($to, $subject, $body, ['mail_type' => 'html']);
    }

    /**
     * @param array<int, array<string, mixed>> $uploadedFiles
     * @return array<int, array<string, mixed>>
     */
    private function attachProjectFiles(
        ProjectFileModel $projectFileModel,
        ?int $projectId,
        array $uploadedFiles,
        ?int $quotationRequestId = null,
        ?int $requestProjectIndex = null
    ): array {
        $files = [];

        foreach ($uploadedFiles as $uploadedFile) {
            if (!is_array($uploadedFile)) {
                continue;
            }

            $plainPassword = $this->generateFilePassword();
            $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(20));
            $row = [
                'project_id' => $projectId,
                'quotation_request_id' => $quotationRequestId,
                'request_project_index' => $requestProjectIndex,
                'original_name' => (string) ($uploadedFile['original_name'] ?? 'file'),
                'stored_name' => (string) ($uploadedFile['stored_name'] ?? ''),
                'mime_type' => (string) ($uploadedFile['mime_type'] ?? ''),
                'size_kb' => (int) ($uploadedFile['size_kb'] ?? 0),
                'relative_path' => (string) ($uploadedFile['relative_path'] ?? ''),
                'full_path' => (string) ($uploadedFile['full_path'] ?? ''),
                'public_token' => $token,
                'access_password_hash' => $passwordHash,
            ];

            $projectFileModel->insert($row);
            $files[] = [
                'project_id' => $projectId,
                'quotation_request_id' => $quotationRequestId,
                'request_project_index' => $requestProjectIndex,
                'original_name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'size_kb' => $row['size_kb'],
                'download_url' => $this->buildFileViewerUrl($token),
                'password' => $plainPassword,
                'password_protected' => true,
            ];
        }

        return $files;
    }

    private function buildFileViewerUrl(string $token): string
    {
        $frontendUrl = trim((string) getenv('app.FrontendURL'));
        if ($frontendUrl === '') {
            $frontendUrl = trim((string) getenv('APP_URL'));
        }
        if ($frontendUrl === '') {
            $frontendUrl = rtrim((string) base_url(), '/');
        }

        return rtrim($frontendUrl, '/') . '/view-file?token=' . urlencode($token);
    }

    private function generateFilePassword(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
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
     * @param array<int, array<string, mixed>> $projects
     * @return array<int, array<string, mixed>>
     */
    private function formatProjectsForResponse(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $projectIds = array_map(static fn(array $project): int => (int) ($project['id'] ?? 0), $projects);
        $projectIds = array_values(array_filter($projectIds, static fn(int $id): bool => $id > 0));

        $categoryIds = array_map(static fn(array $project): int => (int) ($project['category_id'] ?? 0), $projects);
        $categoryIds = array_values(array_unique(array_filter($categoryIds, static fn(int $id): bool => $id > 0)));

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

            $category = $categoriesById[$categoryId] ?? '';
            $services = $servicesByProject[$projectId] ?? [];
            $serviceIds = $serviceIdsByProject[$projectId] ?? [];

            $project['category'] = $category;
            $project['services'] = $services;
            $project['service_ids'] = $serviceIds;
            $project['payment_type'] = (string) ($project['payment_type'] ?? 'fixed_rate');
            $project['hourly_hours'] = $project['hourly_hours'] ?? null;

            unset($project['nature'], $project['trades']);
        }
        unset($project);

        return $projects;
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

    private function isValidE164Phone(string $phone): bool
    {
        $value = trim($phone);
        if ($value === '') {
            return false;
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/', $value) === 1;
    }

    private function isValidPlansUrl(string $url): bool
    {
        $value = trim($url);
        if ($value === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return true;
        }

        // Accept host-only style entries like www.example.com.
        if (stripos($value, 'www.') === 0 && strlen($value) > 4) {
            return filter_var('https://' . $value, FILTER_VALIDATE_URL) !== false;
        }

        return false;
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function normalizeStringList($items): array
    {
        if (is_string($items)) {
            $decoded = json_decode($items, true);
            if (is_array($decoded)) {
                $items = $decoded;
            } else {
                $items = preg_split('/\s*,\s*/', $items) ?: [];
            }
        }

        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $value = trim((string) $item);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
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
    private function looksLikeProjectPayload(array $item): bool
    {
        return isset($item['category'])
            || isset($item['category_id'])
            || isset($item['services'])
            || isset($item['service_ids'])
            || isset($item['scope'])
            || isset($item['project_title']);
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
     * @param mixed $value
     * @return array<int, int>
     */
    private function normalizeServiceIds($value): array
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

        $ids = array_map('intval', $value);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));

        return $ids;
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

                $serviceCategoryIds = array_map(
                    static fn(array $categoryRow): int => (int) ($categoryRow['id'] ?? 0),
                    is_array($service['categories'] ?? null) ? $service['categories'] : []
                );

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
            $item['service_ids'] = array_values(array_unique(array_filter($serviceIds, static fn(int $id): bool => $id > 0)));

            $resolved[] = $item;
        }

        return [
            'projects' => $errors === [] ? $resolved : $projectItems,
            'errors' => $errors,
        ];
    }
}
