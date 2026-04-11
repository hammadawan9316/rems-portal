<?php

use App\Libraries\EmailQueueService;

if (!function_exists('queue_email_job')) {
    /**
     * Push an email job into the local email queue.
     *
     * @param array|string $to
     * @param array<string,mixed> $options
     */
    function queue_email_job(array|string $to, string $subject, string $body, array $options = []): int
    {
        /** @var EmailQueueService $queue */
        $queue = service('emailQueue');

        return $queue->queue($to, $subject, $body, $options);
    }
}

if (!function_exists('add_email_to_queue')) {
    /**
     * Alias for queue_email_job().
     *
     * @param array|string $to
     * @param array<string,mixed> $options
     */
    function add_email_to_queue(array|string $to, string $subject, string $body, array $options = []): int
    {
        return queue_email_job($to, $subject, $body, $options);
    }
}
