<?php

namespace App\Middleware;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class RoleBasedAccessMiddleware implements FilterInterface
{
    /**
     * Process request before controller
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Check if user is authenticated
        if (!isset($request->user)) {
            return $this->unauthorizedResponse('Authentication required');
        }

        $userRoles = $request->user['roles'] ?? [];

        // If arguments provided, check if user has required roles
        if (!empty($arguments)) {
            $requiredRoles = is_array($arguments) ? $arguments : [$arguments];
            $hasRole = false;

            foreach ($requiredRoles as $role) {
                if (in_array($role, $userRoles, true)) {
                    $hasRole = true;
                    break;
                }
            }

            if (!$hasRole) {
                return $this->forbiddenResponse('Insufficient permissions for this action');
            }
        }

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

    /**
     * Forbidden response
     */
    private function forbiddenResponse(string $message): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(403);
        $response->setContentType('application/json');
        $response->setBody(json_encode([
            'success' => false,
            'message' => $message,
            'errors' => [],
        ]));

        return $response;
    }
}
