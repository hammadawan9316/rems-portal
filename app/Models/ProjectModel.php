<?php

namespace App\Models;

use CodeIgniter\Model;

class ProjectModel extends Model
{
    protected $table = 'projects';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'customer_id',
        'project_title',
        'project_description',
        'nature',
        'trades',
        'scope',
        'estimate_type',
        'plans_url',
        'zip_code',
        'deadline',
        'deadline_date',
        'estimated_amount',
        'status',
        'square_order_id',
        'square_estimate_id',
        'square_error',
        'square_sync_attempts',
        'square_sync_queued_at',
        'square_synced_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
