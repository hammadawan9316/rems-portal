<?php

namespace App\Controllers\Api;

use App\Libraries\SquareService;
use App\Models\ProjectModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Square;

class ProjectIntakeController extends BaseApiController
{
    public function submit()
    {
        $data = $this->getRequestData(false);
        $projectItems = $this->extractProjectItems($data);

        if ($projectItems === []) {
            return $this->res->badRequest('At least one project is required.', [
                'projects' => 'Provide project_title or a projects array with one or more items.',
            ]);
        }

        $validation = $this->validateRequest([
            'client_name' => 'required|min_length[2]|max_length[160]',
            'client_email' => 'required|valid_email|max_length[190]',
            'client_phone' => 'permit_empty|max_length[40]',
        ]);

        if ($validation !== true) {
            return $validation;
        }

        $projectErrors = $this->validateProjectItems($projectItems);
        if ($projectErrors !== []) {
            return $this->res->validation($projectErrors);
        }

        $uploadedFiles = $this->storeUploadedFiles();

        if (isset($uploadedFiles['error'])) {
            return $this->res->badRequest('File upload failed.', ['files' => $uploadedFiles['error']]);
        }

        $projectModel = new ProjectModel();

        $square = new SquareService();
        $isSquareConfigured = $square->isConfigured();

        $clientName = trim((string) ($data['client_name'] ?? ''));
        $clientEmail = trim((string) ($data['client_email'] ?? ''));
        $clientPhone = trim((string) ($data['client_phone'] ?? ''));

        $customerId = null;
        $customerError = null;

        if ($isSquareConfigured) {
            try {
                $customer = $square->findOrCreateCustomer(
                    $clientName,
                    $clientEmail,
                    $clientPhone === '' ? null : $clientPhone
                );
                $customerId = $customer['id'];
            } catch (\Throwable $exception) {
                $customerError = $exception->getMessage();
                log_message('error', 'Square customer sync failed for ' . $clientEmail . ': ' . $exception->getMessage());
            }
        }

        $createdProjects = [];
        $squareResults = [];
        $firstFileLink = $uploadedFiles[0]['full_path'] ?? null;

        foreach ($projectItems as $item) {
            $projectData = [
                'client_name' => $clientName,
                'client_email' => $clientEmail,
                'client_phone' => $clientPhone,
                'project_title' => $item['project_title'],
                'project_description' => $item['project_description'],
                'file_links' => json_encode($uploadedFiles, JSON_UNESCAPED_SLASHES),
                'status' => 'project_created',
            ];

            $projectModel->insert($projectData);
            $projectId = (int) $projectModel->getInsertID();

            $squareResult = [
                'configured' => $isSquareConfigured,
                'customer_id' => $customerId,
                'estimate_id' => null,
                'order_id' => null,
                'status' => $isSquareConfigured ? 'pending' : 'skipped',
            ];

            if ($isSquareConfigured && $customerId !== null) {
                try {
                    $estimate = $square->createDraftEstimateForProject(
                        $projectId,
                        $customerId,
                        $projectData['project_title'],
                        $projectData['project_description'],
                        is_string($firstFileLink) ? $firstFileLink : null,
                        $item['estimated_amount']
                    );

                    $projectModel->update($projectId, [
                        'square_customer_id' => $customerId,
                        'square_order_id' => $estimate['order_id'],
                        'square_estimate_id' => $estimate['estimate_id'],
                        'status' => 'estimate_draft_created',
                    ]);

                    $squareResult = [
                        'configured' => true,
                        'customer_id' => $customerId,
                        'estimate_id' => $estimate['estimate_id'],
                        'order_id' => $estimate['order_id'],
                        'status' => $estimate['status'],
                    ];
                } catch (\Throwable $exception) {
                    $projectModel->update($projectId, [
                        'status' => 'square_failed',
                        'square_error' => $exception->getMessage(),
                    ]);

                    $squareResult['status'] = 'failed';
                    $squareResult['error'] = $exception->getMessage();
                    log_message('error', 'Square workflow failed for project ' . $projectId . ': ' . $exception->getMessage());
                }
            } elseif ($isSquareConfigured) {
                $projectModel->update($projectId, [
                    'status' => 'square_failed',
                    'square_error' => $customerError,
                ]);
                $squareResult['status'] = 'failed';
                $squareResult['error'] = $customerError;
            }

            $this->queueOwnerNotification($projectId, $projectData, $uploadedFiles, $squareResult);

            $savedProject = $projectModel->find($projectId);
            if (is_array($savedProject)) {
                $createdProjects[] = $savedProject;
            }
            $squareResults[] = array_merge(['project_id' => $projectId], $squareResult);
        }

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
            'sequence' => [
                'project_created_first' => true,
                'estimate_created_after_project' => true,
            ],
        ], 'Project submitted and workflow executed.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{project_title:string,project_description:string,estimated_amount:int}>
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
                    'project_title' => trim((string) ($project['project_title'] ?? '')),
                    'project_description' => trim((string) ($project['project_description'] ?? '')),
                    'estimated_amount' => max(100, (int) ($project['estimated_amount'] ?? 10000)),
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
            'estimated_amount' => max(100, (int) ($data['estimated_amount'] ?? 10000)),
        ];

        return $items;
    }

    /**
     * @param array<int, array{project_title:string,project_description:string,estimated_amount:int}> $projectItems
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

            if ($item['estimated_amount'] < 100) {
                $errors['projects.' . $index . '.estimated_amount'] = 'Estimated amount must be at least 100.';
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array<string, mixed>>|array{error:string}
     */
    private function storeUploadedFiles(): array
    {
        $allFiles = $this->request->getFiles();
        if ($allFiles === []) {
            return [];
        }

        $flatFiles = [];
        $this->flattenFiles($allFiles, $flatFiles);

        $directory = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return ['error' => 'Unable to create project upload directory.'];
        }

        $uploaded = [];
        foreach ($flatFiles as $file) {
            if (!$file->isValid() || $file->hasMoved()) {
                continue;
            }

            $storedName = $file->getRandomName();
            $file->move($directory, $storedName);

            $uploaded[] = [
                'original_name' => $file->getClientName(),
                'stored_name' => $storedName,
                'mime_type' => $file->getClientMimeType(),
                'size_kb' => (int) ceil($file->getSizeByUnit('kb')),
                'full_path' => $directory . DIRECTORY_SEPARATOR . $storedName,
            ];
        }

        return $uploaded;
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
     * @param array<int, array<string, mixed>> $files
     * @param array<string, mixed> $squareResult
     */
    private function queueOwnerNotification(int $projectId, array $projectData, array $files, array $squareResult): void
    {
        /** @var Square $squareConfig */
        $squareConfig = config('Square');
        $to = trim($squareConfig->ownerNotificationEmail);
        if ($to === '') {
            return;
        }

        $clientName = (string) ($projectData['client_name'] ?? '');
        $clientEmail = (string) ($projectData['client_email'] ?? '');
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

        if ($files !== []) {
            $contentParts[] = '<p><strong>Files Uploaded:</strong> ' . count($files) . '</p>';
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
}
