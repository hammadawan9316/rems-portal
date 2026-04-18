<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Jwt extends BaseConfig
{
    /**
     * Secret key for JWT signing
     * Should be changed in .env file
     */
    public string $secretKey = 'your-secret-key-change-in-env';

    /**
     * JWT expiry time in hours
     */
    public int $expiryHours = 24;

    /**
     * Refresh token expiry time in hours
     */
    public int $refreshExpiryHours = 168; // 7 days

    /**
     * Algorithm used for JWT encoding
     */
    public string $algorithm = 'HS256';

    /**
     * JWT issuer
     */
    public string $issuer = 'rems-portal';

    /**
     * JWT audience
     */
    public string $audience = 'rems-portal-users';
}
