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
        'quotation_id',
        'category_id',
        'project_title',
        'project_description',
        'scope',
        'estimate_type',
        'plans_url',
        'zip_code',
        'deadline',
        'delivery_date',
        'deadline_date',
        'estimated_amount',
        'payment_type',
        'hourly_hours',
        'status',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
