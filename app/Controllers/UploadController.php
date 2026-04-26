<?php

namespace App\Controllers;

class UploadController extends BaseController
{
    public function show(...$segments)
    {
        $uriPath = trim((string) $this->request->getUri()->getPath(), '/');
        if (str_starts_with($uriPath, 'index.php/')) {
            $uriPath = substr($uriPath, strlen('index.php/'));
        }

        $path = '';
        if (str_starts_with($uriPath, 'uploads/')) {
            $path = substr($uriPath, strlen('uploads/'));
        } elseif ($segments !== []) {
            $path = implode('/', array_map(static fn ($segment): string => (string) $segment, $segments));
        }

        $normalizedPath = rawurldecode(trim(str_replace('\\', '/', $path), '/'));
        if ($normalizedPath === '' || str_contains($normalizedPath, '..')) {
            return $this->response->setStatusCode(404)->setBody('File not found');
        }

        $filePath = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath);

        if (!is_file($filePath)) {
            return $this->response->setStatusCode(404)->setBody('File not found');
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        return $this->response
            ->setHeader('Content-Type', $mimeType)
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody((string) file_get_contents($filePath));
    }
}
