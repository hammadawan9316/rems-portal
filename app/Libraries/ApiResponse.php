<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ResponseInterface;

class ResponseService
{
    protected $response;

    public function __construct()
    {
        $this->response = service('response');
    }

    private function send(bool $status, string $message, $data = null, $errors = null, int $code = 200): ResponseInterface
    {
        $body = [
            'status'  => $status,
            'message' => $message,
            'code'    => $code,
        ];

        if (!is_null($data)) {
            $body['data'] = $data;
        }

        if (!is_null($errors)) {
            $body['errors'] = $errors;
        }

        return $this->response
            ->setStatusCode($code)
            ->setJSON($body);
    }

    // ================= SUCCESS =================

    public function ok($data = null, string $message = 'Success'): ResponseInterface
    {
        return $this->send(true, $message, $data, null, 200);
    }

    public function created($data = null, string $message = 'Created successfully'): ResponseInterface
    {
        return $this->send(true, $message, $data, null, 201);
    }

    public function noContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204);
    }

    // ================= ERRORS =================

    public function badRequest(string $message = 'Bad request', $errors = null): ResponseInterface
    {
        return $this->send(false, $message, null, $errors, 400);
    }

    public function unauthorized(string $message = 'Unauthorized'): ResponseInterface
    {
        return $this->send(false, $message, null, null, 401);
    }

    public function forbidden(string $message = 'Forbidden'): ResponseInterface
    {
        return $this->send(false, $message, null, null, 403);
    }

    public function notFound(string $message = 'Resource not found'): ResponseInterface
    {
        return $this->send(false, $message, null, null, 404);
    }

    public function validation($errors, string $message = 'Validation failed'): ResponseInterface
    {
        return $this->send(false, $message, null, $errors, 422);
    }

    public function serverError(string $message = 'Internal server error'): ResponseInterface
    {
        return $this->send(false, $message, null, null, 500);
    }

    // ================= PAGINATION =================

    public function paginated(array $items, int $total, int $page, int $perPage, string $message = 'Success'): ResponseInterface
    {
        return $this->send(true, $message, [
            'items' => $items,
            'pagination' => [
                'total'        => $total,
                'count'        => count($items),
                'per_page'     => $perPage,
                'current_page' => $page,
                'total_pages'  => (int) ceil($total / $perPage),
            ]
        ], null, 200);
    }
}