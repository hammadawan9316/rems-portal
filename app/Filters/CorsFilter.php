<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $allowedOriginsValue = (string) (env('CORS_ALLOWED_ORIGINS') ?? env('cors.allowedOrigins') ?? '*');
        $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedOriginsValue)), static fn (string $origin): bool => $origin !== ''));

        if ($allowedOrigins === []) {
            $allowedOrigins = ['*'];
        }

        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins))
            ? ($origin ?: '*')
            : '*';

        $allowCredentials = (bool) (env('CORS_SUPPORTS_CREDENTIALS') ?? true);

        if ($allowCredentials && $allowOrigin === '*') {
            // Browsers block wildcard origin when credentials are allowed.
            $allowOrigin = $origin !== '' ? $origin : '*';
        }

        $response = service('response');
        $response->setHeader('Access-Control-Allow-Origin', $allowOrigin);
        $response->setHeader('Vary', 'Origin');
        $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
        $response->setHeader('Access-Control-Allow-Credentials', $allowCredentials ? 'true' : 'false');
        $response->setHeader('Access-Control-Max-Age', (string) (env('CORS_MAX_AGE') ?? 3600));

        // Handle preflight OPTIONS request here to avoid route-level 404.
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $response->setStatusCode(204);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $origin = $request->getHeaderLine('Origin');
        $allowOrigin = $response->getHeaderLine('Access-Control-Allow-Origin');

        if ($allowOrigin === '') {
            $allowedOriginsValue = (string) (env('CORS_ALLOWED_ORIGINS') ?? env('cors.allowedOrigins') ?? '*');
            $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $allowedOriginsValue)), static fn (string $item): bool => $item !== ''));
            $allowOrigin = (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true))
                ? ($origin !== '' ? $origin : '*')
                : '*';

            $response->setHeader('Access-Control-Allow-Origin', $allowOrigin);
            $response->setHeader('Vary', 'Origin');
            $response->setHeader('Access-Control-Allow-Headers', 'Origin, X-Requested-With, Content-Type, Accept, Authorization');
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
            $response->setHeader('Access-Control-Allow-Credentials', ((bool) (env('CORS_SUPPORTS_CREDENTIALS') ?? true)) ? 'true' : 'false');
            $response->setHeader('Access-Control-Max-Age', (string) (env('CORS_MAX_AGE') ?? 3600));
        }

        return $response;
    }
}