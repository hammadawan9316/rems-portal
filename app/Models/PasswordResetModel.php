<?php

namespace App\Models;

use CodeIgniter\Model;

class PasswordResetModel extends Model
{
    protected $table = 'password_resets';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';

    protected $allowedFields = [
        'user_id',
        'email',
        'token',
        'token_hash',
        'expires_at',
        'used_at',
        'created_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';

    /**
     * Find active reset token
     */
    public function findActiveToken(string $token): ?array
    {
        $reset = $this->where('token_hash', hash('sha256', $token))
            ->where('used_at', null)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->first();

        return is_array($reset) ? $reset : null;
    }

    /**
     * Create password reset token
     */
    public function createResetToken(int $userId, string $email, int $expiryMinutes = 60): ?string
    {
        // Invalidate previous tokens
        $this->where('user_id', $userId)
            ->where('used_at', null)
            ->delete();

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryMinutes} minutes"));

        if ($this->insert([
            'user_id' => $userId,
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ])) {
            return $token;
        }

        return null;
    }

    /**
     * Mark token as used
     */
    public function markAsUsed(string $token): bool
    {
        $reset = $this->findActiveToken($token);
        if (!is_array($reset)) {
            return false;
        }

        return $this->update($reset['id'], [
            'used_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Get user email from token
     */
    public function getUserEmailFromToken(string $token): ?string
    {
        $reset = $this->findActiveToken($token);
        if (!is_array($reset)) {
            return null;
        }

        return $reset['email'];
    }

    /**
     * Clean up expired tokens
     */
    public function cleanupExpiredTokens(): int
    {
        return $this->where('expires_at <=', date('Y-m-d H:i:s'))
            ->delete();
    }
}
