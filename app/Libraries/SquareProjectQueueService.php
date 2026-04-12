<?php

namespace App\Libraries;

use App\Models\EmailQueueModel;
use App\Models\CustomerModel;
use App\Models\ProjectFileModel;
use App\Models\ProjectModel;
use Config\App;

class SquareProjectQueueService
{
    private const STATUS_PENDING = 0;
    private const STATUS_PROCESSING = 1;

    protected EmailQueueModel $queueModel;
    protected ProjectModel $projectModel;
    protected CustomerModel $customerModel;
    protected ProjectFileModel $projectFileModel;

    public function __construct()
    {
        $this->queueModel = new EmailQueueModel();
        $this->projectModel = new ProjectModel();
        $this->customerModel = new CustomerModel();
        $this->projectFileModel = new ProjectFileModel();
    }

    public function enqueue(int $projectId, string $priority = 'default', int $availableAt = 0): int
    {
        $payload = [
            'job' => 'square_project_sync',
            'project_id' => $projectId,
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
            $projectId = (int) ($payload['project_id'] ?? 0);
            if ($projectId < 1) {
                $this->moveToFailed($job, 'Square queue payload is missing a project_id.');
                $result['failed']++;
                continue;
            }

            $project = $this->projectModel->find($projectId);
            if (!is_array($project)) {
                $this->moveToFailed($job, 'Project not found for Square sync.');
                $result['failed']++;
                continue;
            }

            try {
                $customerProfile = $this->resolveCustomerProfile($project);
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

                $fileRows = $this->projectFileModel
                    ->where('project_id', $projectId)
                    ->orderBy('id', 'ASC')
                    ->findAll();

                $fileLinks = [];
                /** @var App $appConfig */
                $appConfig = config('App');
                $baseUrl = rtrim((string) $appConfig->baseURL, '/');
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

                $estimate = $square->createDraftEstimateForProject(
                    $projectId,
                    (string) $customer['id'],
                    (string) ($project['project_title'] ?? 'Project Estimate'),
                    (string) ($project['project_description'] ?? ''),
                    $project,
                    $fileLinks,
                    $estimatedAmount
                );

                $this->projectModel->update($projectId, [
                    'customer_id' => (int) ($customerProfile['id'] ?? ($project['customer_id'] ?? 0)) ?: null,
                    'square_order_id' => (string) ($estimate['order_id'] ?? null),
                    'square_estimate_id' => (string) ($estimate['estimate_id'] ?? null),
                    'square_error' => null,
                    'square_synced_at' => date('Y-m-d H:i:s'),
                    'status' => 'estimate_draft_created',
                ]);

                $this->queueModel->delete($jobId);
                $result['synced']++;

                $freshProject = $this->projectModel->find($projectId);
                if (is_array($freshProject)) {
                    try {
                        $this->queueCustomerSquareReadyEmail($freshProject);
                    } catch (\Throwable $emailException) {
                        log_message('error', 'Square sync email queue failed. project_id={projectId}, error={error}', [
                            'projectId' => $projectId,
                            'error' => $emailException->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $exception) {
                $attempts = (int) $job['attempts'] + 1;
                $maxAttempts = max(1, (int) ($payload['max_attempts'] ?? 5));

                log_message('error', 'Square queue sync failed. job_id={jobId}, project_id={projectId}, attempt={attempt}/{maxAttempts}, error={error}', [
                    'jobId' => $jobId,
                    'projectId' => $projectId,
                    'attempt' => $attempts,
                    'maxAttempts' => $maxAttempts,
                    'error' => $exception->getMessage(),
                ]);

                $this->projectModel->update($projectId, [
                    'status' => 'square_failed',
                    'square_error' => $exception->getMessage(),
                    'square_sync_attempts' => $attempts,
                ]);

                if ($attempts >= $maxAttempts) {
                    log_message('error', 'Square queue sync moved to failed table. job_id={jobId}, project_id={projectId}, attempts={attempts}, error={error}', [
                        'jobId' => $jobId,
                        'projectId' => $projectId,
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
