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

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */

    public function getAllQuotationRequests(int $page = 1, int $perPage = 20, string $sortBy = 'id', string $sortOrder = 'DESC', string $search = ''): array
    {
        $search = trim($search);
        $sortBy = in_array($sortBy, ['id', 'request_number', 'client_name', 'client_email', 'status', 'created_at'], true) ? $sortBy : 'id';
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $offset = ($page - 1) * $perPage;

        $builder = $this->builder()
            ->select('quotation_requests.*')
            ->join('customers', 'customers.id = quotation_requests.customer_id', 'left');

        if ($search !== '') {
            $builder->groupStart()
                ->like('quotation_requests.request_number', $search)
                ->orLike('quotation_requests.client_name', $search)
                ->orLike('quotation_requests.client_email', $search)
                ->orLike('customers.name', $search)
                ->groupEnd();
        }

        $total = (clone $builder)->countAllResults(false);

        $items = $builder->orderBy($sortBy, $sortOrder)
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
