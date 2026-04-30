<?php

namespace App\Models;

use CodeIgniter\Model;

class BusinessProfileModel extends Model
{
    protected $table = 'business_profiles';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'company_name',
        'admin_name',
        'email',
        'phone',
        'address',
        'website_url',
        'followup_notification_days',
        'followup_notification_text',
        'is_active',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findActive(): ?array
    {
        $row = $this->where('is_active', 1)
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->first();

        return is_array($row) ? $row : null;
    }
}
