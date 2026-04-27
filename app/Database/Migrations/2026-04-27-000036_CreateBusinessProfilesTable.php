<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBusinessProfilesTable extends Migration
{
    public function up()
    {
        $rows = $this->db->query('SHOW TABLES LIKE ' . $this->db->escape('business_profiles'))->getResultArray();
        if ($rows !== []) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'company_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
            ],
            'admin_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'phone' => [
                'type' => 'VARCHAR',
                'constraint' => 60,
                'null' => true,
            ],
            'address' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'website_url' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'is_active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('is_active');
        $this->forge->createTable('business_profiles', true);
    }

    public function down()
    {
        $this->forge->dropTable('business_profiles', true);
    }
}
