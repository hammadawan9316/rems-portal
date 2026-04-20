<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $email = 'admin@example.com';
        $password = '@Liverpool1';

        $userPayload = [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'name' => 'System Admin',
            'phone' => null,
            'company' => null,
            'is_active' => 1,
            'email_verified_at' => $now,
            'updated_at' => $now,
        ];

        $existingUser = $this->db->table('users')
            ->where('email', $email)
            ->get()
            ->getRowArray();

        if (is_array($existingUser)) {
            $userId = (int) ($existingUser['id'] ?? 0);
            if ($userId > 0) {
                $this->db->table('users')->where('id', $userId)->update($userPayload);
            }
        } else {
            $userPayload['created_at'] = $now;
            $this->db->table('users')->insert($userPayload);
            $userId = (int) $this->db->insertID();
        }

        if (($userId ?? 0) <= 0) {
            return;
        }

        $role = $this->db->table('roles')
            ->where('slug', 'admin')
            ->get()
            ->getRowArray();

        if (!is_array($role)) {
            $this->db->table('roles')->insert([
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Administrator with full access',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $roleId = (int) $this->db->insertID();
        } else {
            $roleId = (int) ($role['id'] ?? 0);
        }

        if ($roleId <= 0) {
            return;
        }

        $existingUserRole = $this->db->table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->countAllResults();

        if ($existingUserRole === 0) {
            $this->db->table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $roleId,
                'created_at' => $now,
            ]);
        }
    }
}
