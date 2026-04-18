<?php

namespace App\Models;

use CodeIgniter\Model;

class CustomerModel extends Model
{
    protected $table = 'customers';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useAutoIncrement = true;
    protected $protectFields = true;

    protected $allowedFields = [
        'user_id',
        'name',
        'email',
        'phone',
        'company',
        'square_customer_id',
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByEmail(string $email): ?array
    {
        $customer = $this->where('email', trim($email))->first();

        return is_array($customer) ? $customer : null;
    }

    public function findByUserId(int $userId): ?array
    {
        $customer = $this->where('user_id', $userId)->first();

        return is_array($customer) ? $customer : null;
    }

    public function linkUser(int $customerId, ?int $userId): bool
    {
        return $this->update($customerId, [
            'user_id' => $userId,
        ]);
    }
}
