<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run()
    {
        $data = [
            [
                'name' => 'Customer',
                'slug' => 'customer',
                'description' => 'Regular customer user who can submit projects',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Employee',
                'slug' => 'employee',
                'description' => 'Employee who can manage projects',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Administrator with full access',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        // Using Query Builder
        $this->db->table('roles')->insertBatch($data);
    }
}
