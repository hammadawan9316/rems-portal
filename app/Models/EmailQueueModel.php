<?php

namespace App\Models;

use CodeIgniter\Model;

class EmailQueueModel extends Model
{
    protected $table = 'queue_jobs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'queue',
        'payload',
        'priority',
        'status',
        'attempts',
        'available_at',
        'created_at',
    ];

    protected $useTimestamps = false;
}
