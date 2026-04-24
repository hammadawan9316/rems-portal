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

    /**
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function getAllQuotationRequests(
        int $page = 1,
        int $perPage = 20,
        string $sortBy = 'id',
        string $sortOrder = 'DESC',
        string $search = ''
    ): array {
        $search = trim($search);

        $allowedSort = ['id', 'request_number', 'client_name', 'client_email', 'status', 'created_at'];
        $sortBy = in_array($sortBy, $allowedSort, true) ? $sortBy : 'id';
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $offset = ($page - 1) * $perPage;

        $builder = $this->builder()
            ->select([
                'quotation_requests.id',
                'quotation_requests.customer_id',
                'quotation_requests.request_number',
                'quotation_requests.status',
                'quotation_requests.client_name',
                'quotation_requests.client_email',
                'quotation_requests.client_phone',
                'quotation_requests.company',
                'quotation_requests.description',
                'quotation_requests.notes',
                // ❌ payload_snapshot intentionally excluded
                'quotation_requests.quoted_at',
                'quotation_requests.created_at',
                'quotation_requests.updated_at'
            ])
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

        // ✅ Attach Projects (minimal fields only)
        if (!empty($items)) {
            $requestIds = array_column($items, 'id');

            $projectModel  = new QuotationRequestProjectModel();
            $categoryModel = new CategoryModel();
            $serviceModel  = new ServiceModel();

            $projects = $projectModel
                ->whereIn('quotation_request_id', $requestIds)
                ->findAll();

            $categoryIds = [];
            $serviceIds  = [];

            foreach ($projects as $p) {
                $categoryIds[] = (int) $p['category_id'];

                $ids = json_decode($p['service_ids_json'] ?? '[]', true);
                if (is_array($ids)) {
                    $serviceIds = array_merge($serviceIds, array_map('intval', $ids));
                }
            }

            $categoryIds = array_values(array_unique(array_filter($categoryIds)));
            $serviceIds  = array_values(array_unique(array_filter($serviceIds)));

            // Category map
            $categoryMap = [];
            if (!empty($categoryIds)) {
                foreach ($categoryModel->whereIn('id', $categoryIds)->findAll() as $c) {
                    $categoryMap[(int)$c['id']] = $c['name'];
                }
            }

            // Service map
            $serviceMap = [];
            if (!empty($serviceIds)) {
                foreach ($serviceModel->whereIn('id', $serviceIds)->findAll() as $s) {
                    $serviceMap[(int)$s['id']] = $s['name'];
                }
            }

            // Group projects by request
            $projectsByRequest = [];

            foreach ($projects as $p) {
                $decodedIds = json_decode($p['service_ids_json'] ?? '[]', true);

                $services = [];
                if (is_array($decodedIds)) {
                    foreach ($decodedIds as $sid) {
                        $sid = (int)$sid;
                        if (isset($serviceMap[$sid])) {
                            $services[] = $serviceMap[$sid];
                        }
                    }
                }

                $projectsByRequest[$p['quotation_request_id']][] = [
                    'category'      => $categoryMap[(int)$p['category_id']] ?? '',
                    'services'      => $services,
                    'deadline'      => $p['deadline'] ?? null,
                    'deadline_date' => $p['deadline_date'] ?? null,
                ];
            }

            // Attach to main items
            foreach ($items as &$item) {
                $item['projects'] = $projectsByRequest[$item['id']] ?? [];
            }
            unset($item);
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
