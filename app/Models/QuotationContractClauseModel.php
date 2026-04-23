<?php

namespace App\Models;

use CodeIgniter\Model;

class QuotationContractClauseModel extends Model
{
    protected $table = 'quotation_contract_clauses';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'quotation_contract_id',
        'clause_id',
        'sort_order',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
