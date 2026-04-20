<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $allowedOrigins = getenv('cors.allowedOrigins')
            ? array_map('trim', explode(',', getenv('cors.allowedOrigins')))
            : ['*'];

        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins))
            ? ($origin ?: '*')
            : '*';

        // Always add headers
        header("Access-Control-Allow-Origin: $allowOrigin");
        header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
        header("Access-Control-Allow-Credentials: true");
        header("Access-Control-Max-Age: 3600");

        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'options') {
            header('HTTP/1.1 200 OK');
            exit;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }
}