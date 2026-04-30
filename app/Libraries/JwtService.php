<?php

namespace App\Libraries;

class JwtService
{
    private string $secretKey;
    private string $algorithm = 'HS256';
    private int $expirationTime = 86400; // 24 hours in seconds
    private const REVOKED_TOKEN_PREFIX = 'jwt_revoked_';

    public function __construct()
    {
        /** @var \Config\Jwt $jwtConfig */
        $jwtConfig = config('Jwt');
        
        $this->secretKey = getenv('JWT_SECRET_KEY') ?: $jwtConfig->secretKey;
        $expiryHours = (int) getenv('JWT_EXPIRY_HOURS') ?: $jwtConfig->expiryHours;
        $this->expirationTime = $expiryHours * 3600;
    }

    /**
     * Generate JWT token
     */
    public function generateToken(array $payload): string
    {
        $header = [
            'alg' => $this->algorithm,
            'typ' => 'JWT',
        ];

        $issuedAt = time();
        $payload['iat'] = $issuedAt;
        $payload['exp'] = $issuedAt + $this->expirationTime;

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signature = hash_hmac('sha256', "{$headerEncoded}.{$payloadEncoded}", $this->secretKey, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "{$headerEncoded}.{$payloadEncoded}.{$signatureEncoded}";
    }

    /**
     * Verify and decode JWT token
     */
    public function verifyAndDecode(string $token): ?array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $signature = hash_hmac(
            'sha256',
            "{$headerEncoded}.{$payloadEncoded}",
            $this->secretKey,
            true
        );
        $signatureExpected = $this->base64UrlEncode($signature);

        if (!hash_equals($signatureExpected, $signatureEncoded)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($payloadEncoded);
        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    public function isTokenRevoked(string $token): bool
    {
        $cache = service('cache');
        return (bool) $cache->get($this->getRevokedTokenCacheKey($token));
    }

    public function revokeToken(string $token): bool
    {
        $payload = $this->verifyAndDecode($token);
        if (!is_array($payload)) {
            return false;
        }

        $expiresAt = (int) ($payload['exp'] ?? 0);
        $ttl = max(60, $expiresAt - time());

        $cache = service('cache');
        return (bool) $cache->save($this->getRevokedTokenCacheKey($token), 1, $ttl);
    }

    /**
     * Generate refresh token
     */
    public function generateRefreshToken(array $payload): string
    {
        // Refresh tokens last longer (typically 7-30 days)
        $originalExpiry = $this->expirationTime;
        $this->expirationTime = (int) getenv('JWT_REFRESH_EXPIRY_HOURS') ?: 168; // 7 days
        $this->expirationTime *= 3600;

        $token = $this->generateToken($payload);
        $this->expirationTime = $originalExpiry;

        return $token;
    }

    /**
     * Extract token from Authorization header
     */
    public static function extractToken(string $authorizationHeader): ?string
    {
        $header = trim($authorizationHeader);
        if (strpos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return null;
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $input): string
    {
        return strtr(rtrim(base64_encode($input), '='), '+/', '-_');
    }

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $input): ?string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded !== false ? $decoded : null;
    }

    private function getRevokedTokenCacheKey(string $token): string
    {
        return self::REVOKED_TOKEN_PREFIX . hash('sha256', trim($token));
    }
}
