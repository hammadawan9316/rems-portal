<?php

namespace App\Models;

use CodeIgniter\Model;

class QuotationModel extends Model
{
    protected $table = 'quotations';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'customer_id',
        'quote_number',
        'title',
        'status',
        'notes',
        'square_order_id',
        'square_invoice_id',
        'square_status',
        'square_error',
        'square_synced_at',
        'submitted_at',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function generateQuoteNumber(): string
    {
        return 'QT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
