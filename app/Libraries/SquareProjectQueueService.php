<?php

namespace App\Libraries;

use App\Models\EmailQueueModel;
use App\Models\CategoryModel;
use App\Models\CustomerModel;
use App\Models\ProjectFileModel;
use App\Models\ProjectModel;
use App\Models\ProjectServiceModel;
use App\Models\QuotationModel;
use Config\App;

class SquareProjectQueueService
{
    private const STATUS_PENDING = 0;
    private const STATUS_PROCESSING = 1;

    protected EmailQueueModel $queueModel;
    protected ProjectModel $projectModel;
    protected ProjectServiceModel $projectServiceModel;
    protected CategoryModel $categoryModel;
    protected CustomerModel $customerModel;
    protected ProjectFileModel $projectFileModel;
    protected QuotationModel $quotationModel;

    public function __construct()
    {
        $this->queueModel = new EmailQueueModel();
        $this->projectModel = new ProjectModel();
        $this->projectServiceModel = new ProjectServiceModel();
        $this->categoryModel = new CategoryModel();
        $this->customerModel = new CustomerModel();
        $this->projectFileModel = new ProjectFileModel();
        $this->quotationModel = new QuotationModel();
    }

    public function enqueue(int $quotationId, string $priority = 'default', int $availableAt = 0): int
    {
        $payload = [
            'job' => 'square_quotation_sync',
            'quotation_id' => $quotationId,
            'created_at_iso' => date('c'),
            'max_attempts' => 5,
        ];

        $this->queueModel->insert([
            'queue' => 'square_projects',
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'priority' => $priority === '' ? 'default' : $priority,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => $availableAt > 0 ? $availableAt : time(),
            'created_at' => time(),
        ]);

        return (int) $this->queueModel->getInsertID();
    }

    /**
     * @return array{processed:int,synced:int,failed:int}
     */
    public function processBatch(int $limit = 20): array
    {
        $jobs = $this->queueModel
            ->where('queue', 'square_projects')
            ->where('status', self::STATUS_PENDING)
            ->where('available_at <=', time())
            ->orderBy('id', 'ASC')
            ->findAll(max(1, $limit));

        $result = [
            'processed' => 0,
            'synced' => 0,
            'failed' => 0,
        ];

        $square = new SquareService();
        if (!$square->isConfigured()) {
            return $result;
        }

        foreach ($jobs as $job) {
            $result['processed']++;
            $jobId = (int) $job['id'];
            $this->queueModel->update($jobId, ['status' => self::STATUS_PROCESSING]);

            $payload = json_decode((string) ($job['payload'] ?? ''), true);
            $quotationId = (int) ($payload['quotation_id'] ?? 0);
            if ($quotationId < 1) {
                $this->moveToFailed($job, 'Square queue payload is missing a quotation_id.');
                $result['failed']++;
                continue;
            }

            $quotation = $this->quotationModel->find($quotationId);
            if (!is_array($quotation)) {
                $this->moveToFailed($job, 'Quotation not found for Square sync.');
                $result['failed']++;
                continue;
            }

            $projects = $this->projectModel
                ->where('quotation_id', $quotationId)
                ->orderBy('id', 'ASC')
                ->findAll();

            if ($projects === []) {
                $this->moveToFailed($job, 'Quotation has no projects for Square sync.');
                $result['failed']++;
                continue;
            }

            $projectIds = array_map(static fn (array $project): int => (int) ($project['id'] ?? 0), $projects);
            $projectIds = array_values(array_filter($projectIds, static fn (int $id): bool => $id > 0));
            $categoryIds = array_map(static fn (array $project): int => (int) ($project['category_id'] ?? 0), $projects);
            $categoryIds = array_values(array_unique(array_filter($categoryIds, static fn (int $id): bool => $id > 0)));

            $categoryNamesById = [];
            if ($categoryIds !== []) {
                $categoryRows = $this->categoryModel->whereIn('id', $categoryIds)->findAll();
                foreach ($categoryRows as $categoryRow) {
                    if (!is_array($categoryRow)) {
                        continue;
                    }

                    $categoryId = (int) ($categoryRow['id'] ?? 0);
                    if ($categoryId > 0) {
                        $categoryNamesById[$categoryId] = trim((string) ($categoryRow['name'] ?? ''));
                    }
                }
            }

            $servicesByProject = $this->projectServiceModel->getServiceNamesByProjectIds($projectIds);

            try {
                $customerProfile = $this->resolveCustomerProfileForQuotation($quotation, $projects);
                if ($customerProfile['email'] === '') {
                    throw new \RuntimeException('Customer email is required before Square sync can run.');
                }
                $customer = $square->findOrCreateCustomer(
                    $customerProfile['name'],
                    $customerProfile['email'],
                    $customerProfile['phone']
                );
                if (($customerProfile['id'] ?? 0) > 0) {
                    $this->customerModel->update((int) $customerProfile['id'], [
                        'square_customer_id' => (string) ($customer['id'] ?? ''),
                    ]);
                }

                /** @var App $appConfig */
                $appConfig = config('App');
                $baseUrl = rtrim((string) $appConfig->baseURL, '/');
                $projectEntries = [];
                foreach ($projects as $project) {
                    if (!is_array($project)) {
                        continue;
                    }

                    $projectId = (int) ($project['id'] ?? 0);
                    if ($projectId < 1) {
                        continue;
                    }

                    $fileRows = $this->projectFileModel
                        ->where('project_id', $projectId)
                        ->orderBy('id', 'ASC')
                        ->findAll();

                    $fileLinks = [];
                    foreach ($fileRows as $fileRow) {
                        if (!is_array($fileRow)) {
                            continue;
                        }

                        $token = trim((string) ($fileRow['public_token'] ?? ''));
                        if ($token !== '') {
                            $fileLinks[] = $baseUrl . '/api/projects/files/' . $token;
                            continue;
                        }

                        $fallbackPath = trim((string) ($fileRow['relative_path'] ?? ''));
                        if ($fallbackPath !== '') {
                            $fileLinks[] = $fallbackPath;
                        }
                    }

                    $estimatedAmount = null;
                    if (($project['estimated_amount'] ?? null) !== null && (string) ($project['estimated_amount'] ?? '') !== '') {
                        $estimatedAmount = (int) $project['estimated_amount'];
                    }

                    $paymentType = trim((string) ($project['payment_type'] ?? 'fixed_rate'));
                    $hourlyHours = isset($project['hourly_hours']) && $project['hourly_hours'] !== '' ? (string) $project['hourly_hours'] : null;
                    $discountType = trim((string) ($project['discount_type'] ?? ''));
                    $discountValue = isset($project['discount_value']) && $project['discount_value'] !== '' ? (string) $project['discount_value'] : null;
                    $discountScope = trim((string) ($project['discount_scope'] ?? 'project_total'));

                    $categoryId = (int) ($project['category_id'] ?? 0);
                    $services = $servicesByProject[$projectId] ?? [];

                    $projectData = $project;
                    $projectData['category'] = $categoryNamesById[$categoryId] ?? '';
                    $projectData['services'] = $services;
                    $projectData['payment_type'] = $paymentType !== '' ? $paymentType : 'fixed_rate';
                    $projectData['hourly_hours'] = $hourlyHours;
                    $projectData['discount_type'] = $discountType !== '' ? $discountType : null;
                    $projectData['discount_value'] = $discountValue;
                    $projectData['discount_scope'] = $discountScope !== '' ? $discountScope : 'project_total';

                    $projectEntries[] = [
                        'project_id' => $projectId,
                        'project_title' => (string) ($project['project_title'] ?? 'Project Estimate'),
                        'project_description' => (string) ($project['project_description'] ?? ''),
                        'project_data' => $projectData,
                        'file_links' => $fileLinks,
                        'estimated_amount_cents' => $estimatedAmount,
                    ];
                }

                if ($projectEntries === []) {
                    throw new \RuntimeException('No valid projects available to build Square quotation invoice.');
                }

                $estimate = $square->createDraftEstimateForQuotation(
                    $quotationId,
                    (string) $customer['id'],
                    (string) ($quotation['description'] ?? ('Quotation #' . $quotationId)),
                    $quotation,
                    $projectEntries
                );

                $this->quotationModel->update($quotationId, [
                    'status' => 'estimate_draft_created',
                    'square_order_id' => (string) ($estimate['order_id'] ?? null),
                    'square_invoice_id' => (string) ($estimate['estimate_id'] ?? null),
                    'square_status' => (string) ($estimate['status'] ?? null),
                    'square_error' => null,
                    'square_synced_at' => date('Y-m-d H:i:s'),
                ]);

                foreach ($projectEntries as $entry) {
                    $projectId = (int) ($entry['project_id'] ?? 0);
                    if ($projectId < 1) {
                        continue;
                    }

                    $this->projectModel->update($projectId, [
                        'customer_id' => (int) ($customerProfile['id'] ?? 0) ?: null,
                        'status' => 'estimate_draft_created',
                    ]);
                }

                $this->queueModel->delete($jobId);
                $result['synced']++;

                $freshProject = $this->projectModel
                    ->where('quotation_id', $quotationId)
                    ->orderBy('id', 'ASC')
                    ->first();
                if (is_array($freshProject)) {
                    try {
                        $this->queueCustomerSquareReadyEmail($freshProject);
                    } catch (\Throwable $emailException) {
                        log_message('error', 'Square sync email queue failed. project_id={projectId}, error={error}', [
                            'projectId' => (int) ($freshProject['id'] ?? 0),
                            'error' => $emailException->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $exception) {
                $attempts = (int) $job['attempts'] + 1;
                $maxAttempts = max(1, (int) ($payload['max_attempts'] ?? 5));

                log_message('error', 'Square queue sync failed. job_id={jobId}, quotation_id={quotationId}, attempt={attempt}/{maxAttempts}, error={error}', [
                    'jobId' => $jobId,
                    'quotationId' => $quotationId,
                    'attempt' => $attempts,
                    'maxAttempts' => $maxAttempts,
                    'error' => $exception->getMessage(),
                ]);

                $this->quotationModel->update($quotationId, [
                    'status' => 'square_failed',
                    'square_error' => $exception->getMessage(),
                ]);

                $projects = $this->projectModel
                    ->where('quotation_id', $quotationId)
                    ->orderBy('id', 'ASC')
                    ->findAll();
                foreach ($projects as $project) {
                    if (!is_array($project)) {
                        continue;
                    }
                    $projectId = (int) ($project['id'] ?? 0);
                    if ($projectId < 1) {
                        continue;
                    }
                    $this->projectModel->update($projectId, [
                        'status' => 'square_failed',
                    ]);
                }

                if ($attempts >= $maxAttempts) {
                    log_message('error', 'Square queue sync moved to failed table. job_id={jobId}, quotation_id={quotationId}, attempts={attempts}, error={error}', [
                        'jobId' => $jobId,
                        'quotationId' => $quotationId,
                        'attempts' => $attempts,
                        'error' => $exception->getMessage(),
                    ]);
                    $this->moveToFailed($job, $exception->getMessage());
                    $result['failed']++;
                    continue;
                }

                $this->queueModel->update($jobId, [
                    'status' => self::STATUS_PENDING,
                    'attempts' => $attempts,
                    'available_at' => time() + 120,
                ]);
            }
        }

        return $result;
    }

    private function moveToFailed(array $job, string $exception): void
    {
        $db = $this->queueModel->db;
        $db->table('queue_jobs_failed')->insert([
            'connection' => 'database',
            'queue' => (string) ($job['queue'] ?? 'square_projects'),
            'payload' => (string) ($job['payload'] ?? '{}'),
            'priority' => (string) ($job['priority'] ?? 'default'),
            'exception' => $exception,
            'failed_at' => time(),
        ]);

        $this->queueModel->delete((int) ($job['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $project
     */
    private function queueCustomerSquareReadyEmail(array $project): void
    {
        $customerProfile = $this->resolveCustomerProfile($project);
        $to = trim($customerProfile['email']);
        if ($to === '') {
            return;
        }

        $emailQueue = service('emailQueue');
        $projectId = (int) ($project['id'] ?? 0);
        $subject = 'Estimate Draft Created for Project #' . $projectId;

        $body = $emailQueue->renderTemplate([
            'subject' => $subject,
            'recipientName' => $customerProfile['name'] === '' ? 'Customer' : $customerProfile['name'],
            'headline' => 'Your estimate draft is ready',
            'contentHtml' => '<p>Your request has been processed and an estimate draft was created in our system.</p>'
                . '<p><strong>Project ID:</strong> ' . esc((string) $projectId) . '</p>'
                . '<p><strong>Title:</strong> ' . esc((string) ($project['project_title'] ?? '')) . '</p>',
            'actionText' => 'Contact Support',
            'actionUrl' => 'mailto:' . $to,
        ]);

        $emailQueue->queue($to, $subject, $body, ['mail_type' => 'html']);
    }

    /**
     * @param array<string, mixed> $quotation
     * @param array<int, array<string, mixed>> $projects
     * @return array{id:int,name:string,email:string,phone:?string,square_customer_id:?string}
     */
    private function resolveCustomerProfileForQuotation(array $quotation, array $projects): array
    {
        $customerId = (int) ($quotation['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = $this->customerModel->find($customerId);
            if (is_array($customer)) {
                return [
                    'id' => (int) ($customer['id'] ?? 0),
                    'name' => trim((string) ($customer['name'] ?? '')),
                    'email' => trim((string) ($customer['email'] ?? '')),
                    'phone' => (($customer['phone'] ?? '') === '' ? null : (string) $customer['phone']),
                    'square_customer_id' => (($customer['square_customer_id'] ?? '') === '' ? null : (string) $customer['square_customer_id']),
                ];
            }
        }

        if ($projects !== []) {
            $first = $projects[0];
            if (is_array($first)) {
                return $this->resolveCustomerProfile($first);
            }
        }

        return [
            'id' => 0,
            'name' => '',
            'email' => '',
            'phone' => null,
            'square_customer_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $project
     * @return array{id:int,name:string,email:string,phone:?string,square_customer_id:?string}
     */
    private function resolveCustomerProfile(array $project): array
    {
        $customerId = (int) ($project['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = $this->customerModel->find($customerId);
            if (is_array($customer)) {
                return [
                    'id' => (int) ($customer['id'] ?? 0),
                    'name' => trim((string) ($customer['name'] ?? '')),
                    'email' => trim((string) ($customer['email'] ?? '')),
                    'phone' => (($customer['phone'] ?? '') === '' ? null : (string) $customer['phone']),
                    'square_customer_id' => (($customer['square_customer_id'] ?? '') === '' ? null : (string) $customer['square_customer_id']),
                ];
            }
        }

        return [
            'id' => 0,
            'name' => '',
            'email' => '',
            'phone' => null,
            'square_customer_id' => null,
        ];
    }
}
