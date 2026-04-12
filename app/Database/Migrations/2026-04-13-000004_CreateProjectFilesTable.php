<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProjectFilesTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'project_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'original_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'stored_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'mime_type' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'size_kb' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'default' => 0,
            ],
            'relative_path' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
            ],
            'full_path' => [
                'type' => 'VARCHAR',
                'constraint' => 600,
            ],
            'public_token' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
            ],
            'access_password_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
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
        $this->forge->addKey('project_id');
        $this->forge->addKey('public_token');
        $this->forge->addForeignKey('project_id', 'projects', 'id', 'CASCADE', 'CASCADE', 'fk_project_files_project_id');
        $this->forge->createTable('project_files', true);
    }

    public function down()
    {
        $this->forge->dropTable('project_files', true);
    }
}
