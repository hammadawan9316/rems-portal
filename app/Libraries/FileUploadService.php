<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;

class FileUploadService
{
    /**
     * Upload one file from a request.
     *
     * @return array{status:bool,message:string,data?:array,errors?:array}
     */
    public function uploadSingle(
        IncomingRequest $request,
        string $fieldName,
        string $directory = '',
        array $allowedExtensions = [],
        int $maxSizeKb = 5120
    ): array {
        $file = $request->getFile($fieldName);

        if (!$file instanceof UploadedFile) {
            return $this->error('Request was aborted or file was not sent.', [
                $fieldName => 'No upload payload found for this field.',
            ]);
        }

        if (!$file->isValid()) {
            return $this->error($this->mapUploadError($file->getError()), [
                $fieldName => $file->getErrorString(),
            ]);
        }

        $extension = strtolower((string) $file->getExtension());
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
            return $this->error('Invalid file type.', [
                $fieldName => 'Allowed types: ' . implode(', ', $allowedExtensions),
            ]);
        }

        $sizeKb = (int) ceil($file->getSizeByUnit('kb'));
        if ($sizeKb > $maxSizeKb) {
            return $this->error('File too large.', [
                $fieldName => "Maximum size is {$maxSizeKb}KB.",
            ]);
        }

        $targetDir = $this->resolveTargetDirectory($directory);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return $this->error('Unable to create upload directory.');
        }

        $storedName = $file->getRandomName();
        $file->move($targetDir, $storedName);

        return [
            'status' => true,
            'message' => 'File uploaded successfully.',
            'data' => [
                'field'         => $fieldName,
                'original_name' => $file->getClientName(),
                'stored_name'   => $storedName,
                'extension'     => $extension,
                'mime_type'     => $file->getClientMimeType(),
                'size_kb'       => $sizeKb,
                'directory'     => trim($directory, '/\\'),
                'relative_path' => $this->relativePath($directory, $storedName),
                'full_path'     => rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $storedName,
            ],
        ];
    }

    /**
     * Build a stable directory path under writable/uploads.
     */
    private function resolveTargetDirectory(string $directory): string
    {
        $safeDirectory = str_replace(['..', './', '.\\'], '', trim($directory, '/\\'));

        if ($safeDirectory === '') {
            return rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads';
        }

        return rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $safeDirectory;
    }

    private function relativePath(string $directory, string $storedName): string
    {
        $safeDirectory = trim(str_replace('..', '', $directory), '/\\');

        if ($safeDirectory === '') {
            return $storedName;
        }

        return $safeDirectory . '/' . $storedName;
    }

    private function mapUploadError(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Uploaded file exceeds server size limits.';
            case UPLOAD_ERR_PARTIAL:
                return 'Upload was interrupted due to network issues. Please retry.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server is missing a temporary upload directory.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server failed to write uploaded file.';
            case UPLOAD_ERR_EXTENSION:
                return 'A server extension blocked the upload.';
            default:
                return 'File upload failed.';
        }
    }

    /**
     * @return array{status:bool,message:string,errors?:array}
     */
    private function error(string $message, ?array $errors = null): array
    {
        $result = [
            'status' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $result['errors'] = $errors;
        }

        return $result;
    }
}
