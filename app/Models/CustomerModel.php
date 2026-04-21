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

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateCustomers(string $search = '', int $perPage = 20, int $offset = 0): array
    {
        $search = trim($search);

        $countBuilder = $this->builder()->select('COUNT(*) AS total', false);
        if ($search !== '') {
            $countBuilder->groupStart()
                ->like('name', $search)
                ->orLike('email', $search)
                ->orLike('phone', $search)
                ->orLike('company', $search)
                ->groupEnd();
        }

        $totalRow = $countBuilder->get()->getRowArray();
        $total = (int) ($totalRow['total'] ?? 0);

        $itemsBuilder = $this->builder();
        if ($search !== '') {
            $itemsBuilder->groupStart()
                ->like('name', $search)
                ->orLike('email', $search)
                ->orLike('phone', $search)
                ->orLike('company', $search)
                ->groupEnd();
        }

        $items = $itemsBuilder
            ->orderBy('id', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
