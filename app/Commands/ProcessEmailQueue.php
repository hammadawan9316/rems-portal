<?php

namespace App\Commands;

use App\Libraries\EmailQueueService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProcessEmailQueue extends BaseCommand
{
    protected $group = 'Queue';
    protected $name = 'queue:emails';
    protected $description = 'Process queued email jobs.';
    protected $usage = 'queue:emails [--limit 20]';
    protected $options = [
        '--limit' => 'Number of pending jobs to process in one run. Default: 20',
    ];

    public function run(array $params)
    {
        $limit = (int) (CLI::getOption('limit') ?? 20);
        if ($limit < 1) {
            $limit = 20;
        }

        $service = new EmailQueueService();
        $result = $service->processBatch($limit);

        CLI::write('Email queue processed.', 'green');
        CLI::write('Processed: ' . $result['processed']);
        CLI::write('Sent: ' . $result['sent']);
        CLI::write('Failed: ' . $result['failed']);
    }
}
