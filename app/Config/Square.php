<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Square extends BaseConfig
{
    public string $baseUrl;
    public string $apiVersion;
    public string $accessToken;
    public string $locationId;
    public string $currency;
    public string $ownerNotificationEmail;

    public function __construct()
    {
        parent::__construct();

        $this->baseUrl = env('square.baseUrl', 'https://connect.squareup.com');
        $this->apiVersion = env('square.apiVersion', '2025-10-16');
        $this->accessToken = env('square.accessToken');
        $this->locationId = env('square.locationId');
        $this->currency = env('square.currency', 'USD');
        $this->ownerNotificationEmail = env('square.ownerNotificationEmail');
    }
}