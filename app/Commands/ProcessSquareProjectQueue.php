<?php

namespace App\Commands;

use App\Libraries\SquareProjectQueueService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProcessSquareProjectQueue extends BaseCommand
{
    protected $group = 'Queue';
    protected $name = 'queue:square-projects';
    protected $description = 'Process queued Square project sync jobs.';
    protected $usage = 'queue:square-projects [--limit 20]';
    protected $options = [
        '--limit' => 'Number of pending jobs to process in one run. Default: 20',
    ];

    public function run(array $params)
    {
        $limit = (int) (CLI::getOption('limit') ?? 20);
        if ($limit < 1) {
            $limit = 20;
        }

        $service = new SquareProjectQueueService();
        $result = $service->processBatch($limit);

        CLI::write('Square project queue processed.', 'green');
        CLI::write('Processed: ' . $result['processed']);
        CLI::write('Synced: ' . $result['synced']);
        CLI::write('Failed: ' . $result['failed']);
    }
}
