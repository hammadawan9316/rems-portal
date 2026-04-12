<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\FileUploadService;
use App\Libraries\ResponseService;

class BaseApiController extends BaseController
{
    /**
     * @var ResponseService
     */
    protected $res;

    /**
     * @var FileUploadService
     */
    protected $uploadService;

    public function __construct()
    {
        $this->res = new ResponseService();
        $this->uploadService = new FileUploadService();
    }

    /**
     * Get JSON request as array
     */
    protected function getJson()
    {
        try {
            $json = $this->request->getJSON(true);
        } catch (\Throwable $exception) {
            $json = [];
        }

        return is_array($json) ? $json : [];
    }

    /**
     * Get request data from JSON, form-data, x-www-form-urlencoded, raw input, and query string.
     */
    protected function getRequestData(bool $includeQuery = true): array
    {
        $data = [];

        try {
            $json = $this->request->getJSON(true);
        } catch (\Throwable $exception) {
            $json = [];
        }

        if (is_array($json) && !empty($json)) {
            $data = array_merge($data, $json);
        }

        $raw = $this->request->getRawInput();
        if (is_array($raw) && !empty($raw)) {
            $data = array_merge($data, $raw);
        }

        $post = $this->request->getPost();
        if (is_array($post) && !empty($post)) {
            $data = array_merge($data, $post);
        }

        if ($includeQuery) {
            $query = $this->request->getGet();
            if (is_array($query) && !empty($query)) {
                $data = array_merge($data, $query);
            }
        }

        return $data;
    }

    /**
     * Validate request helper
     */
    protected function validateRequest(array $rules)
    {
        if (!$this->validate($rules)) {
            return $this->res->validation($this->validator->getErrors());
        }

        return true;
    }
}