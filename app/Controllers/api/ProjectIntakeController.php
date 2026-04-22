<?php

namespace App\Controllers\Api;

use App\Libraries\SquareProjectQueueService;
use App\Libraries\SquareService;
use App\Models\CategoryModel;
use App\Models\CustomerModel;
use App\Models\ProjectFileModel;
use App\Models\ProjectModel;
use App\Models\ProjectServiceModel;
use App\Models\QuotationModel;
use App\Models\ServiceModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Square;

class ProjectIntakeController extends BaseApiController
{
    public function submit()
    {
        $data = $this->normalizeIncomingPayload($this->getRequestData(false));
        $projectItems = $this->extractProjectItems($data);
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

        $projectModel = new ProjectModel();
        $projectServiceModel = new ProjectServiceModel();
        $customerModel = new CustomerModel();
        $quotationModel = new QuotationModel();
        $projectFileModel = new ProjectFileModel();
        $squareQueue = new SquareProjectQueueService();
        $square = new SquareService();
        $isSquareConfigured = $square->isConfigured();

        $clientName = trim((string) ($data['client_name'] ?? ''));
        $clientEmail = trim((string) ($data['client_email'] ?? ''));
        $clientPhone = trim((string) ($data['client_phone'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));
        $customerId = $this->resolveCustomerId($customerModel, $clientName, $clientEmail, $clientPhone, $company);

        $quotationId = $this->createQuotation(
            $quotationModel,
            $customerId,
            $data
        );

        $createdProjects = [];
        $squareResults = [];
        $secureFiles = [];
        $projectsByFile = [];  // Track files by project for quotation email

        foreach ($projectItems as $index => $item) {
            $projectData = [
                'customer_id' => $customerId,
                'quotation_id' => $quotationId,
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
                'status' => 'submitted',
            ];

            $projectModel->insert($projectData);
            $projectId = (int) $projectModel->getInsertID();
            $projectServiceModel->replaceServices($projectId, is_array($item['service_ids'] ?? null) ? $item['service_ids'] : []);

            $projectUploadedFiles = [];
            if (isset($uploadedFilesByProject[$index]) && is_array($uploadedFilesByProject[$index])) {
                $projectUploadedFiles = $uploadedFilesByProject[$index];
            }

            $projectSecureFiles = $this->attachProjectFiles(
                $projectFileModel,
                $projectId,
                $projectUploadedFiles
            );
            $secureFiles = array_merge($secureFiles, $projectSecureFiles);
            $projectsByFile[$projectId] = $projectSecureFiles;

            $squareResult = [
                'configured' => $isSquareConfigured,
                'customer_id' => null,
                'estimate_id' => null,
                'order_id' => null,
                'status' => $isSquareConfigured ? 'queued' : 'skipped',
            ];

            $savedProject = $projectModel->find($projectId);
            if (is_array($savedProject)) {
                $createdProjects[] = $savedProject;
            }
            $squareResults[] = array_merge(['project_id' => $projectId], $squareResult);
        }

        if ($isSquareConfigured && $quotationId !== null) {
            $squareQueue->enqueue($quotationId);
        }

        $createdProjects = $this->formatProjectsForResponse($createdProjects);

        // Send quotation-based owner notification (consolidated for all projects)
        $this->queueOwnerNotificationForQuotation(
            $quotationId,
            $createdProjects,
            $projectsByFile,
            $clientName,
            $clientEmail,
            $squareResults
        );

        $this->queueCustomerSubmittedNotification($clientEmail, $clientName, $quotationId, $createdProjects, $secureFiles);

        $isMultiple = count($projectItems) > 1;
        $singleProject = $createdProjects[0] ?? null;
        $singleSquare = $squareResults[0] ?? null;

        return $this->res->created([
            'quotation_id' => $quotationId,
            'project' => $singleProject,
            'square' => $singleSquare,
            'projects' => $createdProjects,
            'square_results' => $squareResults,
            'project_count' => count($createdProjects),
            'multiple_projects' => $isMultiple,
            'files' => $uploadedFiles,
            'files_by_project' => $uploadedFilesByProject,
            'secure_files' => $secureFiles,
            'sequence' => [
                'project_created_first' => true,
                'estimate_created_after_project' => true,
                'square_processing' => 'queued_for_cron',
            ],
        ], 'Project submitted successfully. Square sync queued for background processing.');
    }

    /**
     * @param array<int, array<string, mixed>> $projectItems
     */
    private function createQuotation(
        QuotationModel $quotationModel,
        ?int $customerId,
        array $data
    ): ?int {
        $quoteNumber = $quotationModel->generateQuoteNumber();
        $description = trim((string) ($data['description'] ?? ($data['title'] ?? '')));
        $notes = trim((string) ($data['notes'] ?? ''));

        $quotationModel->insert([
            'customer_id' => $customerId,
            'quote_number' => $quoteNumber,
            'description' => $description !== '' ? $description : null,
            'status' => 'submitted',
            'notes' => $notes !== '' ? $notes : null,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        $id = (int) $quotationModel->getInsertID();

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
        if ($passwordHash !== '') {
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

                $result = $this->uploadService->uploadMany($flatProjectFiles, $directory, [], 512000);
                $uploaded = is_array($result['data'] ?? null) ? $result['data'] : [];

                if (($result['status'] ?? false) !== true && $uploaded === []) {
                    $errorMessages = [];
                    $uploadErrors = $result['errors'] ?? [];
                    foreach ($uploadErrors as $error) {
                        if (is_array($error['errors'] ?? null)) {
                            foreach ($error['errors'] as $fieldError) {
                                $errorMessages[] = $fieldError;
                            }
                        } elseif (isset($error['message'])) {
                            $errorMessages[] = $error['message'];
                        }
                    }
                    $errorMsg = !empty($errorMessages) ? implode(' | ', $errorMessages) : 'Unable to store uploaded files for project index ' . $index . '.';
                    return ['error' => $errorMsg];
                }

                $indexInt = (int) $index;
                if (!array_key_exists($indexInt, $byProject)) {
                    $byProject[$indexInt] = [];
                }
                $byProject[$indexInt] = array_values(array_merge($byProject[$indexInt], $uploaded));
                $uploadedAll = array_values(array_merge($uploadedAll, $uploaded));
            }
        }

        $legacyFiles = $allFiles['files'] ?? null;
        if ($legacyFiles !== null) {
            if ($projectCount > 1 && $uploadedAll === []) {
                return ['error' => 'For multiple projects use project-specific file fields: projects[index][files][].'];
            }

            $flatLegacyFiles = [];
            $this->flattenFiles(['files' => $legacyFiles], $flatLegacyFiles);
            if ($flatLegacyFiles !== []) {
                $result = $this->uploadService->uploadMany($flatLegacyFiles, $directory, [], 512000);
                $uploaded = is_array($result['data'] ?? null) ? $result['data'] : [];

                if (($result['status'] ?? false) !== true && $uploaded === []) {
                    $errorMessages = [];
                    $uploadErrors = $result['errors'] ?? [];
                    foreach ($uploadErrors as $error) {
                        if (is_array($error['errors'] ?? null)) {
                            foreach ($error['errors'] as $fieldError) {
                                $errorMessages[] = $fieldError;
                            }
                        } elseif (isset($error['message'])) {
                            $errorMessages[] = $error['message'];
                        }
                    }
                    $errorMsg = !empty($errorMessages) ? implode(' | ', $errorMessages) : 'Unable to store uploaded files.';
                    return ['error' => $errorMsg];
                }

                $targetIndex = 0;
                if (!array_key_exists($targetIndex, $byProject)) {
                    $byProject[$targetIndex] = [];
                }

                $byProject[$targetIndex] = array_values(array_merge($byProject[$targetIndex], $uploaded));
                $uploadedAll = array_values(array_merge($uploadedAll, $uploaded));
            }
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
     * Send quotation-based notification to owner/admin with all projects
     *
     * @param int|null $quotationId
     * @param array<int, array<string, mixed>> $projects
     * @param array<int, array<int, array<string, mixed>>> $projectsByFile
     * @param string $clientName
     * @param string $clientEmail
     * @param array<int, array<string, mixed>> $squareResults
     */
    private function queueOwnerNotificationForQuotation(
        ?int $quotationId,
        array $projects,
        array $projectsByFile,
        string $clientName,
        string $clientEmail,
        array $squareResults
    ): void {
        /** @var Square $squareConfig */
        $squareConfig = config('Square');
        $to = trim($squareConfig->ownerNotificationEmail);
        if ($to === '' || $quotationId === null) {
            return;
        }

        $projectCount = count($projects);
        $quotationModel = new QuotationModel();
        $quotation = $quotationModel->find($quotationId);

        $quotationNumber = '';
        if (is_array($quotation)) {
            $quotationNumber = (string) ($quotation['quote_number'] ?? '');
        }

        $contentParts = [
            '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">A new quotation with ' . esc((string) $projectCount) . ' project(s) was submitted from the website.</p>',
            
            // Quotation Header
            '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;border-collapse:collapse;">',
            '<tr><td style="padding:12px;background:#0f172a;"><strong style="color:#ffffff;">Quotation Details</strong></td></tr>',
            '<tr><td style="padding:12px;">',
            ($quotationNumber !== '' ? '<strong>Quote Number:</strong> ' . esc($quotationNumber) . '<br>' : ''),
            '<strong>Quote ID:</strong> #' . esc((string) $quotationId) . '<br>',
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
            $contentParts[] = '<tr><td style="padding:12px;background:#0f172a;"><strong style="color:#ffffff;">Project #' . esc((string) $projectId) . ': ' . esc($projectTitle) . '</strong></td></tr>';
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
            $projectFiles = $projectsByFile[$projectId] ?? [];
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
        $queuedCount = count(array_filter($squareStatuses, static fn ($s) => $s === 'queued'));
        $skippedCount = count(array_filter($squareStatuses, static fn ($s) => $s === 'skipped'));

        $contentParts[] = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;border-collapse:collapse;">';
        $contentParts[] = '<tr><td style="padding:12px;background:#f8fafc;border-left:3px solid #0f172a;"><strong style="color:#0f172a;">Square Integration Status</strong></td></tr>';
        $contentParts[] = '<tr><td style="padding:12px;">';
        if ($queuedCount > 0) {
            $contentParts[] = '<p style="margin:0 0 6px 0;"><strong>Queued for Processing:</strong> <span style="background:#d1fae5;padding:4px 8px;border-radius:4px;color:#065f46;">' . esc((string) $queuedCount) . ' project(s)</span></p>';
        }
        if ($skippedCount > 0) {
            $contentParts[] = '<p style="margin:0;"><strong>Skipped:</strong> <span style="background:#fee2e2;padding:4px 8px;border-radius:4px;color:#7f1d1d;">' . esc((string) $skippedCount) . ' project(s)</span></p>';
        }
        $contentParts[] = '</td></tr>';
        $contentParts[] = '</table>';

        $emailQueue = service('emailQueue');
        $body = $emailQueue->renderTemplate([
            'subject' => 'New Quotation Submission - ' . ($quotationNumber !== '' ? $quotationNumber : '#' . $quotationId),
            'recipientName' => 'Admin',
            'headline' => 'New Quotation Submission Received',
            'contentHtml' => implode('', $contentParts),
            'actionText' => 'Review in Dashboard',
            'actionUrl' => base_url('dashboard/quotations/' . $quotationId),
        ]);

        queue_email_job($to, 'New Quotation Submission - ' . ($quotationNumber !== '' ? $quotationNumber : '#' . $quotationId), $body, [
            'mail_type' => 'html',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     */
    private function queueCustomerSubmittedNotification(
        string $email,
        string $name,
        ?int $quotationId,
        array $projects,
        array $secureFiles
    ): void
    {
        $to = trim($email);
        if ($to === '') {
            return;
        }

        $projectCount = count($projects);
        $recipientName = $name === '' ? 'Customer' : $name;
        $quotationNumber = '';

        if ($quotationId !== null) {
            $quotation = (new QuotationModel())->find($quotationId);
            if (is_array($quotation)) {
                $quotationNumber = trim((string) ($quotation['quote_number'] ?? ''));
            }
        }

        // Build comprehensive project details for customer
        $projectsHtml = '';
        foreach ($projects as $index => $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = (int) ($project['id'] ?? 0);
            $projectTitle = (string) ($project['project_title'] ?? '');
            $category = (string) ($project['category'] ?? 'N/A');
            $services = $this->normalizeStringList($project['services'] ?? []);
            $paymentType = (string) ($project['payment_type'] ?? 'fixed_rate');
            $estimatedAmount = (string) ($project['estimated_amount'] ?? '');
            $hourlyHours = (string) ($project['hourly_hours'] ?? '');
            $deadline = (string) ($project['deadline'] ?? '');
            $deliveryDate = (string) ($project['delivery_date'] ?? '');

            $projectsHtml .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:16px 0;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;border-collapse:collapse;">';
            $projectsHtml .= '<tr><td style="padding:12px;background:#0f172a;"><strong style="color:#ffffff;">Project #' . esc((string) $projectId) . ': ' . esc($projectTitle) . '</strong></td></tr>';
            $projectsHtml .= '<tr><td style="padding:12px;">';
            
            // Category and Services
            $projectsHtml .= '<p style="margin:0 0 8px 0;"><strong>Category:</strong> ' . esc($category) . '</p>';
            $projectsHtml .= '<p style="margin:0 0 8px 0;"><strong>Services:</strong> ' . esc($services === [] ? 'N/A' : implode(', ', $services)) . '</p>';
            
            // Billing Info
            $projectsHtml .= '<p style="margin:0 0 8px 0;"><strong>Payment Type:</strong> ' . esc($paymentType === 'hourly' ? 'Hourly' : 'Fixed Rate') . '</p>';
            if ($estimatedAmount !== '' && $estimatedAmount !== '0.00') {
                $projectsHtml .= '<p style="margin:0 0 8px 0;"><strong>Estimated Amount:</strong> $' . esc($estimatedAmount) . '</p>';
            }
            if ($paymentType === 'hourly' && $hourlyHours !== '' && $hourlyHours !== '0.00') {
                $projectsHtml .= '<p style="margin:0 0 8px 0;"><strong>Hours:</strong> ' . esc($hourlyHours) . '</p>';
            }
            if ($deadline !== '') {
                $projectsHtml .= '<p style="margin:0;"><strong>Bid Deadline:</strong> ' . esc($deadline) . '</p>';
            }
            if ($deliveryDate !== '') {
                $projectsHtml .= '<p style="margin:0;"><strong>Delivery Date:</strong> ' . esc($deliveryDate) . '</p>';
            }
            
            $projectsHtml .= '</td></tr>';
            $projectsHtml .= '</table>';
        }

        $fileLinksHtml = '';
        if ($secureFiles !== []) {
            $fileLinksHtml .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:20px 0;border-collapse:collapse;">';
            $fileLinksHtml .= '<tr><td style="padding:12px;background:#f8fafc;border-left:3px solid #0f172a;"><strong style="color:#0f172a;">Your Uploaded Files</strong></td></tr>';
            $fileLinksHtml .= '<tr><td style="padding:12px;">';
            $fileLinksHtml .= '<p style="margin:0 0 12px 0;font-size:14px;color:#6b7280;">Download your files using the links and passwords provided below.</p>';
            
            foreach ($secureFiles as $file) {
                $name = (string) ($file['original_name'] ?? 'File');
                $url = (string) ($file['download_url'] ?? '');
                $password = (string) ($file['password'] ?? '');
                
                if ($url === '') {
                    continue;
                }

                $fileLinksHtml .= '<div style="margin:12px 0;padding:10px;background:#f0f4f8;border-radius:6px;border-left:2px solid #0f172a;">';
                $fileLinksHtml .= '<p style="margin:0 0 6px 0;"><strong>' . esc($name) . '</strong></p>';
                $fileLinksHtml .= '<p style="margin:0 0 6px 0;"><a href="' . esc($url) . '" style="color:#0f172a;text-decoration:none;word-break:break-all;">' . esc($url) . '</a></p>';
                if ($password !== '') {
                    $fileLinksHtml .= '<p style="margin:0;font-size:13px;"><strong>Password:</strong> <code style="background:#ffffff;padding:4px 6px;border-radius:3px;font-family:monospace;border:1px solid #e5e7eb;">' . esc($password) . '</code></p>';
                }
                $fileLinksHtml .= '</div>';
            }

            $fileLinksHtml .= '</td></tr>';
            $fileLinksHtml .= '</table>';
        }

        $contentHtml = '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">Thank you for submitting your quotation request. We have received it and our team is reviewing your projects.</p>'
            . ($quotationId !== null ? '<p style="margin:14px 0 6px 0;font-size:15px;line-height:1.6;"><strong>Quotation ID:</strong> #' . esc((string) $quotationId) . '</p>' : '')
            . ($quotationNumber !== '' ? '<p style="margin:6px 0 14px 0;font-size:15px;line-height:1.6;"><strong>Quote Number:</strong> ' . esc($quotationNumber) . '</p>' : '')
            . '<p style="margin:14px 0;font-size:15px;line-height:1.6;"><strong>Total Projects Submitted:</strong> ' . esc((string) $projectCount) . '</p>'
            . '<div style="margin:20px 0;">' . $projectsHtml . '</div>'
            . $fileLinksHtml
            . '<p style="margin:20px 0 0 0;font-size:14px;color:#6b7280;line-height:1.6;">You will receive updates as we process your request. If you have any questions, please don\'t hesitate to reach out to us.</p>';

        $emailQueue = service('emailQueue');
        $subject = 'Quotation submission received' . ($quotationNumber !== '' ? ' - ' . $quotationNumber : ($quotationId !== null ? ' #' . $quotationId : ''));
        $body = $emailQueue->renderTemplate([
            'subject' => $subject,
            'recipientName' => $recipientName,
            'headline' => 'We received your quotation request',
            'contentHtml' => $contentHtml,
            'actionText' => 'View Your Quotation',
            'actionUrl' => $quotationId !== null ? base_url('customer/quotations/' . $quotationId) : base_url('customer/projects'),
        ]);

        queue_email_job($to, $subject, $body, ['mail_type' => 'html']);
    }

    /**
     * @param array<int, array<string, mixed>> $uploadedFiles
     * @return array<int, array<string, mixed>>
     */
    private function attachProjectFiles(ProjectFileModel $projectFileModel, int $projectId, array $uploadedFiles): array
    {
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
                'original_name' => $row['original_name'],
                'mime_type' => $row['mime_type'],
                'size_kb' => $row['size_kb'],
                'download_url' => base_url('api/projects/files/' . $token),
                'password' => $plainPassword,
                'password_protected' => true,
            ];
        }

        return $files;
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
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

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
                    static fn (array $categoryRow): int => (int) ($categoryRow['id'] ?? 0),
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
            $item['service_ids'] = array_values(array_unique(array_filter($serviceIds, static fn (int $id): bool => $id > 0)));

            $resolved[] = $item;
        }

        return [
            'projects' => $errors === [] ? $resolved : $projectItems,
            'errors' => $errors,
        ];
    }
}
