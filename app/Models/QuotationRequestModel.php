<?php

namespace App\Models;

use CodeIgniter\Model;

class QuotationRequestModel extends Model
{
    protected $table = 'quotation_requests';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'customer_id',
        'request_number',
        'status',
        'client_name',
        'client_email',
        'client_phone',
        'company',
        'description',
        'notes',
        'payload_snapshot',
        'quoted_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function generateRequestNumber(): string
    {
        return 'QR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
