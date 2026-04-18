<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'email',
        'password_hash',
        'name',
        'phone',
        'company',
        'is_active',
        'email_verified_at',
        'last_login_at',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';
    protected $deletedField = 'deleted_at';

    protected $validationRules = [
        'email' => 'required|valid_email|is_unique[users.email,id,{id}]|max_length[190]',
        'password_hash' => 'required|min_length[8]',
        'name' => 'required|min_length[2]|max_length[160]',
        'phone' => 'permit_empty|max_length[20]',
        'company' => 'permit_empty|max_length[190]',
    ];

    protected $validationMessages = [];
    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array
    {
        $user = $this->where('email', trim($email))
            ->where('is_active', true)
            ->first();

        return is_array($user) ? $user : null;
    }

    /**
     * Get user with roles
     */
    public function getUserWithRoles(int $userId): ?array
    {
        $user = $this->find($userId);
        if (!is_array($user)) {
            return null;
        }

        $db = \Config\Database::connect();
        $roles = $db->table('user_roles')
            ->join('roles', 'roles.id = user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->get()
            ->getResultArray();

        $user['roles'] = array_map(static function ($role) {
            return [
                'id' => $role['role_id'],
                'name' => $role['name'],
                'slug' => $role['slug'],
            ];
        }, $roles);

        return $user;
    }

    /**
     * Assign role to user
     */
    public function assignRole(int $userId, int $roleId): bool
    {
        $db = \Config\Database::connect();

        $existing = $db->table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->countAllResults();

        if ($existing > 0) {
            return true;
        }

        return $db->table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Check if user has role
     */
    public function hasRole(int $userId, string $roleName): bool
    {
        $db = \Config\Database::connect();

        $result = $db->table('user_roles')
            ->join('roles', 'roles.id = user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->where('roles.slug', $roleName)
            ->countAllResults();

        return $result > 0;
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin(int $userId): bool
    {
        return $this->update($userId, [
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
