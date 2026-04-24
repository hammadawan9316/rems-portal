<?php

namespace App\Models;

use CodeIgniter\Model;

class QuotationRequestProjectModel extends Model
{
    protected $table = 'quotation_request_projects';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'quotation_request_id',
        'request_project_index',
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
        'service_ids_json',
        'raw_payload',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
