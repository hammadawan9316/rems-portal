<?php

namespace App\Controllers\Api;

use App\Libraries\SquareProjectQueueService;
use App\Libraries\SquareService;
use App\Models\CustomerModel;
use App\Models\ProjectFileModel;
use App\Models\ProjectModel;
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
                'projects' => 'Provide project_title or a projects array with one or more items, or the new payload format.',
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

        $uploadedFilesResult = $this->storeUploadedFilesByProject($projectCount);
        if (isset($uploadedFilesResult['error'])) {
            return $this->res->badRequest('File upload failed.', ['files' => $uploadedFilesResult['error']]);
        }
        $uploadedFilesByProject = $uploadedFilesResult['by_project'] ?? [];
        $uploadedFiles = $uploadedFilesResult['all'] ?? [];

        $projectModel = new ProjectModel();
        $customerModel = new CustomerModel();
        $projectFileModel = new ProjectFileModel();
        $squareQueue = new SquareProjectQueueService();
        $square = new SquareService();
        $isSquareConfigured = $square->isConfigured();

        $clientName = trim((string) ($data['client_name'] ?? ''));
        $clientEmail = trim((string) ($data['client_email'] ?? ''));
        $clientPhone = trim((string) ($data['client_phone'] ?? ''));
        $company = trim((string) ($data['company'] ?? ''));
        $customerId = $this->resolveCustomerId($customerModel, $clientName, $clientEmail, $clientPhone, $company);

        $createdProjects = [];
        $squareResults = [];
        $secureFiles = [];

        foreach ($projectItems as $index => $item) {
            $projectData = [
                'customer_id' => $customerId,
                'project_title' => $item['project_title'],
                'project_description' => $item['project_description'],
                'nature' => $item['nature'],
                'trades' => json_encode($item['trades'], JSON_UNESCAPED_SLASHES),
                'scope' => $item['scope'],
                'estimate_type' => $item['estimate_type'],
                'plans_url' => $item['plans_url'],
                'zip_code' => $item['zip_code'],
                'deadline' => $item['deadline'],
                'deadline_date' => $item['deadline_date'],
                'estimated_amount' => null,
                'status' => $isSquareConfigured ? 'square_queued' : 'square_skipped',
                'square_sync_queued_at' => $isSquareConfigured ? date('Y-m-d H:i:s') : null,
            ];

            $projectModel->insert($projectData);
            $projectId = (int) $projectModel->getInsertID();

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

            $squareResult = [
                'configured' => $isSquareConfigured,
                'customer_id' => null,
                'estimate_id' => null,
                'order_id' => null,
                'status' => $isSquareConfigured ? 'queued' : 'skipped',
            ];

            if ($isSquareConfigured) {
                $squareQueue->enqueue($projectId);
            }

            $this->queueOwnerNotification(
                $projectId,
                $projectData,
                $clientName,
                $clientEmail,
                $projectSecureFiles,
                $squareResult
            );

            $savedProject = $projectModel->find($projectId);
            if (is_array($savedProject)) {
                $createdProjects[] = $savedProject;
            }
            $squareResults[] = array_merge(['project_id' => $projectId], $squareResult);
        }

        $this->queueCustomerSubmittedNotification($clientEmail, $clientName, $createdProjects, $secureFiles);

        $isMultiple = count($projectItems) > 1;
        $singleProject = $createdProjects[0] ?? null;
        $singleSquare = $squareResults[0] ?? null;

        return $this->res->created([
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
     * @return array<int, array{project_title:string,project_description:string:int,nature:string,trades:array<int,string>,scope:string,estimate_type:string,plans_url:string,zip_code:string,deadline:string,deadline_date:?string}>
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
                    'project_title' => $this->resolveProjectTitle($project),
                    'project_description' => trim((string) ($project['project_description'] ?? ($project['scope'] ?? ''))),
                        'estimated_amount' => null,
                    'nature' => trim((string) ($project['nature'] ?? '')),
                    'trades' => $this->normalizeTrades($project['trades'] ?? []),
                    'scope' => trim((string) ($project['scope'] ?? '')),
                    'estimate_type' => trim((string) ($project['estimateType'] ?? ($project['estimate_type'] ?? ''))),
                    'plans_url' => trim((string) ($project['plansUrl'] ?? ($project['plans_url'] ?? ''))),
                    'zip_code' => trim((string) ($project['zipCode'] ?? ($project['zip_code'] ?? ''))),
                    'deadline' => trim((string) ($project['deadline'] ?? '')),
                    'deadline_date' => $this->normalizeDateString($project['deadlineDate'] ?? ($project['deadline_date'] ?? null)),
                ];
            }

            return $items;
        }

        $singleTitle = trim((string) ($data['project_title'] ?? ''));
        if ($singleTitle === '') {
            return [];
        }

        $items[] = [
            'project_title' => $singleTitle,
            'project_description' => trim((string) ($data['project_description'] ?? '')),
                'estimated_amount' => null,
            'nature' => trim((string) ($data['nature'] ?? '')),
            'trades' => $this->normalizeTrades($data['trades'] ?? []),
            'scope' => trim((string) ($data['scope'] ?? '')),
            'estimate_type' => trim((string) ($data['estimateType'] ?? ($data['estimate_type'] ?? ''))),
            'plans_url' => trim((string) ($data['plansUrl'] ?? ($data['plans_url'] ?? ''))),
            'zip_code' => trim((string) ($data['zipCode'] ?? ($data['zip_code'] ?? ''))),
            'deadline' => trim((string) ($data['deadline'] ?? '')),
            'deadline_date' => $this->normalizeDateString($data['deadlineDate'] ?? ($data['deadline_date'] ?? null)),
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
    * @param array<int, array{project_title:string,project_description:string,estimated_amount:?int,nature:string,trades:array<int,string>,scope:string,estimate_type:string,plans_url:string,zip_code:string,deadline:string,deadline_date:?string}> $projectItems
     * @return array<string, string>
     */
    private function validateProjectItems(array $projectItems): array
    {
        $errors = [];

        foreach ($projectItems as $index => $item) {
            if ($item['project_title'] === '') {
                $errors['projects.' . $index . '.project_title'] = 'Project title is required.';
                continue;
            }

            $titleLength = mb_strlen($item['project_title']);
            if ($titleLength < 3 || $titleLength > 190) {
                $errors['projects.' . $index . '.project_title'] = 'Project title must be between 3 and 190 characters.';
            }


            if ($item['plans_url'] !== '' && !filter_var($item['plans_url'], FILTER_VALIDATE_URL)) {
                $errors['projects.' . $index . '.plansUrl'] = 'Plans URL must be a valid URL.';
            }

            if ($item['deadline_date'] !== null && strtotime((string) $item['deadline_date']) === false) {
                $errors['projects.' . $index . '.deadlineDate'] = 'Deadline date must be a valid date.';
            }
        }

        return $errors;
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

                $result = $this->uploadService->uploadMany($flatProjectFiles, $directory, [], 10240);
                $uploaded = is_array($result['data'] ?? null) ? $result['data'] : [];

                if (($result['status'] ?? false) !== true && $uploaded === []) {
                    return ['error' => 'Unable to store uploaded files for project index ' . $index . '.'];
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
                $result = $this->uploadService->uploadMany($flatLegacyFiles, $directory, [], 10240);
                $uploaded = is_array($result['data'] ?? null) ? $result['data'] : [];

                if (($result['status'] ?? false) !== true && $uploaded === []) {
                    return ['error' => 'Unable to store uploaded files.'];
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
     * @param array<string, mixed> $projectData
     * @param array<int, array<string, mixed>> $secureFiles
     * @param array<string, mixed> $squareResult
     */
    private function queueOwnerNotification(
        int $projectId,
        array $projectData,
        string $clientName,
        string $clientEmail,
        array $secureFiles,
        array $squareResult
    ): void
    {
        /** @var Square $squareConfig */
        $squareConfig = config('Square');
        $to = trim($squareConfig->ownerNotificationEmail);
        if ($to === '') {
            return;
        }

        $projectTitle = (string) ($projectData['project_title'] ?? '');
        $projectDescription = (string) ($projectData['project_description'] ?? '');

        $contentParts = [
            '<p>A new project was submitted from the website.</p>',
            '<p><strong>Project ID:</strong> ' . esc((string) $projectId) . '</p>',
            '<p><strong>Client:</strong> ' . esc($clientName) . ' (' . esc($clientEmail) . ')</p>',
            '<p><strong>Title:</strong> ' . esc($projectTitle) . '</p>',
            '<p><strong>Description:</strong><br>' . nl2br(esc($projectDescription)) . '</p>',
            '<p><strong>Square Status:</strong> ' . esc((string) ($squareResult['status'] ?? 'skipped')) . '</p>',
        ];

        if ($secureFiles !== []) {
            $contentParts[] = '<p><strong>Files Uploaded:</strong> ' . count($secureFiles) . '</p>';
            $links = array_map(static function (array $file): string {
                $name = (string) ($file['original_name'] ?? 'File');
                $url = (string) ($file['download_url'] ?? '');
                if ($url === '') {
                    return '';
                }

                return '<li>' . esc($name) . ': <a href="' . esc($url) . '">' . esc($url) . '</a></li>';
            }, $secureFiles);

            $links = array_values(array_filter($links));
            if ($links !== []) {
                $contentParts[] = '<p><strong>Secure File Links:</strong></p><ul>' . implode('', $links) . '</ul>';
            }

            $passwordRows = array_map(static function (array $file): string {
                $name = (string) ($file['original_name'] ?? 'File');
                $password = (string) ($file['password'] ?? '');
                if ($password === '') {
                    return '';
                }

                return '<li>' . esc($name) . ': ' . esc($password) . '</li>';
            }, $secureFiles);
            $passwordRows = array_values(array_filter($passwordRows));
            if ($passwordRows !== []) {
                $contentParts[] = '<p><strong>File Passwords:</strong></p><ul>' . implode('', $passwordRows) . '</ul>';
            }
        }

        $emailQueue = service('emailQueue');
        $body = $emailQueue->renderTemplate([
            'subject' => 'New Project Submission #' . $projectId,
            'recipientName' => 'Owner',
            'headline' => 'New project requires review',
            'contentHtml' => implode('', $contentParts),
            'actionText' => 'Open Square Dashboard',
            'actionUrl' => 'https://squareup.com/dashboard',
        ]);

        queue_email_job($to, 'New Project Submission #' . $projectId, $body, [
            'mail_type' => 'html',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     */
    private function queueCustomerSubmittedNotification(
        string $email,
        string $name,
        array $projects,
        array $secureFiles
    ): void
    {
        $to = trim($email);
        if ($to === '') {
            return;
        }

        $projectCount = count($projects);
        $projectIds = array_map(static function ($project): string {
            return (string) ($project['id'] ?? '');
        }, $projects);

        $emailQueue = service('emailQueue');
        $subject = 'Project submission received';

        $fileLinksHtml = '';
        if ($secureFiles !== []) {
            $links = array_map(static function (array $file): string {
                $name = (string) ($file['original_name'] ?? 'File');
                $url = (string) ($file['download_url'] ?? '');
                if ($url === '') {
                    return '';
                }

                return '<li>' . esc($name) . ': <a href="' . esc($url) . '">' . esc($url) . '</a></li>';
            }, $secureFiles);

            $links = array_values(array_filter($links));
            if ($links !== []) {
                $fileLinksHtml .= '<p><strong>Your Secure File Links:</strong></p><ul>' . implode('', $links) . '</ul>';
            }

            $passwordRows = array_map(static function (array $file): string {
                $name = (string) ($file['original_name'] ?? 'File');
                $password = (string) ($file['password'] ?? '');
                if ($password === '') {
                    return '';
                }

                return '<li>' . esc($name) . ': ' . esc($password) . '</li>';
            }, $secureFiles);
            $passwordRows = array_values(array_filter($passwordRows));
            if ($passwordRows !== []) {
                $fileLinksHtml .= '<p><strong>File Passwords:</strong></p><ul>' . implode('', $passwordRows) . '</ul>';
            }
        }

        $body = $emailQueue->renderTemplate([
            'subject' => $subject,
            'recipientName' => $name === '' ? 'Customer' : $name,
            'headline' => 'We received your project request',
            'contentHtml' => '<p>Thanks for your submission. Your project request is now in our queue.</p>'
                . '<p><strong>Projects Submitted:</strong> ' . esc((string) $projectCount) . '</p>'
                . '<p><strong>Project IDs:</strong> ' . esc(implode(', ', array_filter($projectIds))) . '</p>'
                . $fileLinksHtml,
            'actionText' => 'View Square Dashboard',
            'actionUrl' => 'https://squareup.com/dashboard',
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

    /**
     * @param mixed $trades
     * @return array<int, string>
     */
    private function normalizeTrades($trades): array
    {
        if (!is_array($trades)) {
            return [];
        }

        $items = [];
        foreach ($trades as $trade) {
            $value = trim((string) $trade);
            if ($value !== '') {
                $items[] = $value;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param array<string, mixed> $project
     */
    private function resolveProjectTitle(array $project): string
    {
        $title = trim((string) ($project['project_title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $nature = trim((string) ($project['nature'] ?? ''));
        if ($nature !== '') {
            return $nature;
        }

        $scope = trim((string) ($project['scope'] ?? ''));
        if ($scope !== '') {
            return mb_substr($scope, 0, 190);
        }

        return '';
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
        return isset($item['nature'])
            || isset($item['trades'])
            || isset($item['scope'])
            || isset($item['project_title']);
    }
}
