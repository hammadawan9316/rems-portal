<?php

namespace App\Models;

use CodeIgniter\Model;

class ContractTemplateClauseModel extends Model
{
    protected $table = 'contract_clauses';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'contract_id',
        'clause_id',
        'sort_order',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
}
