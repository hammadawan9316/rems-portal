<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateEmailQueueTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'queue' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'payload' => [
                'type' => 'TEXT',
            ],
            'priority' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'default',
            ],
            'status' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'attempts' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'available_at' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
            'created_at' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('queue');
        $this->forge->addKey('priority');
        $this->forge->addKey('status');
        $this->forge->addKey('available_at');
        $this->forge->createTable('queue_jobs', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'connection' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'queue' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'payload' => [
                'type' => 'TEXT',
            ],
            'priority' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'default'    => 'default',
            ],
            'exception' => [
                'type' => 'TEXT',
            ],
            'failed_at' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('queue');
        $this->forge->createTable('queue_jobs_failed', true);
    }

    public function down()
    {
        $this->forge->dropTable('queue_jobs_failed', true);
        $this->forge->dropTable('queue_jobs', true);
    }
}
