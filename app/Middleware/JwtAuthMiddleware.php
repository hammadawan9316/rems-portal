<?php

namespace App\Middleware;

use App\Libraries\JwtService;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class JwtAuthMiddleware implements FilterInterface
{
    private JwtService $jwtService;

    public function __construct()
    {
        $this->jwtService = new JwtService();
    }

    /**
     * Process request before controller
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');
        $token = JwtService::extractToken($authHeader);

        if (!$token) {
            return $this->unauthorizedResponse('Authorization token required');
        }

        $payload = $this->jwtService->verifyAndDecode($token);
        if (!is_array($payload)) {
            return $this->unauthorizedResponse('Invalid or expired token');
        }

        // Store user in request for later use
        $request->user = $payload;

        return null;
    }

    /**
     * Process response after controller
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    /**
     * Unauthorized response
     */
    private function unauthorizedResponse(string $message): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(401);
        $response->setContentType('application/json');
        $response->setBody(json_encode([
            'success' => false,
            'message' => $message,
            'errors' => [],
        ]));

        return $response;
    }
}
