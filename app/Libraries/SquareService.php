<?php

namespace App\Libraries;

use Config\Square;

class SquareService
{
    private Square $config;

    public function __construct()
    {
        /** @var Square $square */
        $square = config('Square');
        $this->config = $square;
    }

    public function isConfigured(): bool
    {
        return $this->config->accessToken !== '' && $this->config->locationId !== '';
    }

    /**
     * @return array{id:string,created:bool}
     */
    public function findOrCreateCustomer(string $name, string $email, ?string $phone = null): array
    {
        $existing = $this->searchCustomerByEmail($email);
        if ($existing !== null) {
            return ['id' => $existing, 'created' => false];
        }

        $payload = [
            'given_name' => $name,
            'email_address' => $email,
        ];

        if (!empty($phone)) {
            $payload['phone_number'] = $phone;
        }

        $response = $this->request('POST', '/v2/customers', $payload);
        $id = (string) ($response['customer']['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Square customer creation failed.');
        }

        return ['id' => $id, 'created' => true];
    }

    /**
     * Create draft estimate represented as a Square draft invoice for a project.
     *
     * @return array{order_id:string,estimate_id:string,status:string}
     */
    public function createDraftEstimateForProject(
        int $projectId,
        string $customerId,
        string $projectTitle,
        string $description,
        ?string $fileLink = null,
        int $estimatedAmountCents = 10000
    ): array {
        $note = "Project #{$projectId}: {$description}";
        if ($fileLink !== null && $fileLink !== '') {
            $note .= "\n\nAttachment: {$fileLink}";
        }

        $orderPayload = [
            'idempotency_key' => $this->idempotencyKey('order', $projectId),
            'order' => [
                'location_id' => $this->config->locationId,
                'customer_id' => $customerId,
                'reference_id' => 'project-' . $projectId,
                'line_items' => [
                    [
                        'name' => $projectTitle,
                        'quantity' => '1',
                        'note' => $note,
                        'base_price_money' => [
                            'amount' => max(100, $estimatedAmountCents),
                            'currency' => $this->config->currency,
                        ],
                    ],
                ],
            ],
        ];

        $orderRes = $this->request('POST', '/v2/orders', $orderPayload);
        $orderId = (string) ($orderRes['order']['id'] ?? '');
        if ($orderId === '') {
            throw new \RuntimeException('Square order creation failed for project estimate.');
        }

        $invoicePayload = [
            'idempotency_key' => $this->idempotencyKey('invoice', $projectId),
            'invoice' => [
                'location_id' => $this->config->locationId,
                'order_id' => $orderId,
                'primary_recipient' => [
                    'customer_id' => $customerId,
                ],
                'title' => 'Estimate for ' . $projectTitle,
                'description' => 'Draft estimate linked to project #' . $projectId,
                'payment_requests' => [
                    [
                        'request_type' => 'BALANCE',
                        'due_date' => date('Y-m-d', strtotime('+7 days')),
                    ],
                ],
            ],
        ];

        $invoiceRes = $this->request('POST', '/v2/invoices', $invoicePayload);
        $invoiceId = (string) ($invoiceRes['invoice']['id'] ?? '');
        $status = (string) ($invoiceRes['invoice']['status'] ?? 'DRAFT');

        if ($invoiceId === '') {
            throw new \RuntimeException('Square estimate/invoice draft creation failed.');
        }

        return [
            'order_id' => $orderId,
            'estimate_id' => $invoiceId,
            'status' => $status,
        ];
    }

    private function searchCustomerByEmail(string $email): ?string
    {
        $response = $this->request('POST', '/v2/customers/search', [
            'query' => [
                'filter' => [
                    'email_address' => [
                        'exact' => $email,
                    ],
                ],
            ],
            'limit' => 1,
        ]);

        $customer = $response['customers'][0]['id'] ?? null;

        return is_string($customer) && $customer !== '' ? $customer : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Square is not configured. Set access token and location ID.');
        }

        $client = service('curlrequest', [
            'baseURI' => rtrim($this->config->baseUrl, '/'),
            'timeout' => 25,
            'http_errors' => false,
        ]);

        $response = $client->request($method, ltrim($path, '/'), [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config->accessToken,
                'Content-Type' => 'application/json',
                'Square-Version' => $this->config->apiVersion,
            ],
            'json' => $payload,
        ]);

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 400) {
            $error = $decoded['errors'][0]['detail'] ?? $raw;
            throw new \RuntimeException('Square API error: ' . $error);
        }

        return $decoded;
    }

    private function idempotencyKey(string $type, int $projectId): string
    {
        return $type . '-' . $projectId . '-' . bin2hex(random_bytes(8));
    }
}
