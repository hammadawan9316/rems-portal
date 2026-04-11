<?php

namespace App\Libraries;

use App\Models\EmailQueueModel;
use Config\Email as EmailConfig;

class EmailQueueService
{
    private const STATUS_PENDING = 0;
    private const STATUS_PROCESSING = 1;

    protected EmailQueueModel $queueModel;

    public function __construct()
    {
        $this->queueModel = new EmailQueueModel();
    }

    /**
     * Queue an email and return the queue row ID.
     */
    public function queue(
        array|string $to,
        string $subject,
        string $body,
        array $options = []
    ): int {
        $toEmails = $this->normalizeEmails($to);
        if ($toEmails === []) {
            throw new \InvalidArgumentException('At least one recipient is required.');
        }

        $mailType = ($options['mail_type'] ?? 'text') === 'html' ? 'html' : 'text';
        $payload = [
            'job' => 'email',
            'to' => $toEmails,
            'subject' => $subject,
            'body' => $body,
            'mail_type' => $mailType,
            'cc' => $this->normalizeEmails($options['cc'] ?? []),
            'bcc' => $this->normalizeEmails($options['bcc'] ?? []),
            'attachments' => $this->normalizeAttachments($options['attachments'] ?? []),
            'from_email' => isset($options['from_email']) ? trim((string) $options['from_email']) : null,
            'from_name' => isset($options['from_name']) ? trim((string) $options['from_name']) : null,
            'max_attempts' => max(1, (int) ($options['max_attempts'] ?? 3)),
            'created_at_iso' => date('c'),
        ];

        $priority = trim((string) ($options['priority'] ?? 'default'));
        if ($priority === '') {
            $priority = 'default';
        }

        $queue = trim((string) ($options['queue'] ?? 'emails'));
        if ($queue === '') {
            $queue = 'emails';
        }

        $data = [
            'queue' => $queue,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'priority' => $priority,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'available_at' => max(0, (int) ($options['available_at'] ?? time())),
            'created_at' => time(),
        ];

        $this->queueModel->insert($data);

        return (int) $this->queueModel->getInsertID();
    }

    /**
     * Process pending jobs.
     *
     * @return array{processed:int,sent:int,failed:int}
     */
    public function processBatch(int $limit = 20): array
    {
        $now = time();

        $jobs = $this->queueModel
            ->where('status', self::STATUS_PENDING)
            ->where('available_at <=', $now)
            ->orderBy('id', 'ASC')
            ->findAll(max(1, $limit));

        $result = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
        ];

        foreach ($jobs as $job) {
            $result['processed']++;
            $this->queueModel->update((int) $job['id'], ['status' => self::STATUS_PROCESSING]);

            $sent = $this->sendEmailJob($job);
            if ($sent) {
                $result['sent']++;
                continue;
            }

            $result['failed']++;
        }

        return $result;
    }

    /**
     * Render the default Remote Estimation HTML email template.
     */
    public function renderTemplate(array $data = []): string
    {
        return view('emails/remote_estimation', $data);
    }

    private function sendEmailJob(array $job): bool
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $this->moveToFailed((int) $job['id'], $job, 'Invalid JSON payload in queue job.');

            return false;
        }

        $email = service('email');
        $email->clear(true);

        $emailConfig = new EmailConfig();
        $fromEmail = (string) ($payload['from_email'] ?? $emailConfig->fromEmail);
        $fromName = (string) ($payload['from_name'] ?? $emailConfig->fromName);

        if ($fromEmail !== '') {
            $email->setFrom($fromEmail, $fromName ?? '');
        }

        $to = $this->normalizeEmails($payload['to'] ?? []);
        if ($to === []) {
            $this->moveToFailed((int) $job['id'], $job, 'Email queue payload has no recipients.');

            return false;
        }
        $email->setTo($to);

        $cc = $this->normalizeEmails($payload['cc'] ?? []);
        if ($cc !== []) {
            $email->setCC($cc);
        }

        $bcc = $this->normalizeEmails($payload['bcc'] ?? []);
        if ($bcc !== []) {
            $email->setBCC($bcc);
        }

        $email->setSubject((string) ($payload['subject'] ?? ''));
        $email->setMessage((string) ($payload['body'] ?? ''));
        $email->setMailType(($payload['mail_type'] ?? 'text') === 'html' ? 'html' : 'text');

        foreach ($this->normalizeAttachments($payload['attachments'] ?? []) as $path) {
            if (is_file($path)) {
                $email->attach($path);
            }
        }

        $attempts = (int) $job['attempts'] + 1;
        $maxAttempts = max(1, (int) ($payload['max_attempts'] ?? 3));

        if ($email->send()) {
            $this->queueModel->delete((int) $job['id']);

            return true;
        }

        $error = substr((string) $email->printDebugger(['headers']), 0, 5000);
        if ($attempts >= $maxAttempts) {
            $this->moveToFailed((int) $job['id'], $job, $error);

            return false;
        }

        $this->queueModel->update((int) $job['id'], [
            'status' => self::STATUS_PENDING,
            'attempts' => $attempts,
            'available_at' => time() + 60,
        ]);

        return false;
    }

    private function moveToFailed(int $jobId, array $job, string $exception): void
    {
        $db = $this->queueModel->db;
        $db->table('queue_jobs_failed')->insert([
            'connection' => 'database',
            'queue' => (string) ($job['queue'] ?? 'emails'),
            'payload' => (string) ($job['payload'] ?? '{}'),
            'priority' => (string) ($job['priority'] ?? 'default'),
            'exception' => $exception,
            'failed_at' => time(),
        ]);

        $this->queueModel->delete($jobId);
    }

    private function normalizeEmails(array|string $emails): array
    {
        $items = is_array($emails) ? $emails : explode(',', $emails);

        $normalized = [];
        foreach ($items as $email) {
            $candidate = trim((string) $email);
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function splitEmails(string $emails): array
    {
        if ($emails === '') {
            return [];
        }

        return $this->normalizeEmails(explode(',', $emails));
    }

    private function implodeEmails(array|string $emails): ?string
    {
        $normalized = $this->normalizeEmails($emails);

        return $normalized === [] ? null : implode(',', $normalized);
    }

    private function encodeAttachments(array $attachments): ?string
    {
        $items = [];
        foreach ($attachments as $path) {
            $candidate = trim((string) $path);
            if ($candidate !== '') {
                $items[] = $candidate;
            }
        }

        if ($items === []) {
            return null;
        }

        return json_encode(array_values(array_unique($items)), JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param mixed $attachments
     * @return array<int, string>
     */
    private function normalizeAttachments($attachments): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $items = [];
        foreach ($attachments as $path) {
            $candidate = trim((string) $path);
            if ($candidate !== '') {
                $items[] = $candidate;
            }
        }

        return array_values(array_unique($items));
    }
}
