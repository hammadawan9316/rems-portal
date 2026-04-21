<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the normalized projects table used by intake and Square queue workflows.
 */
class CreateProjectsTable extends Migration
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
            'customer_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
            ],
            'project_title' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
            ],
            'project_description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'nature' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
            ],
            'trades' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'scope' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'estimate_type' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'plans_url' => [
                'type' => 'VARCHAR',
                'constraint' => 500,
                'null' => true,
            ],
            'zip_code' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
            ],
            'deadline' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'delivery_date' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'deadline_date' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'estimated_amount' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => true,
                'default' => null,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'default' => 'submitted',
            ],
            'square_order_id' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
            ],
            'square_estimate_id' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
            ],
            'square_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'square_sync_attempts' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'default' => 0,
            ],
            'square_sync_queued_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'square_synced_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addKey('customer_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'SET NULL', 'CASCADE', 'fk_projects_customer_id');
        $this->forge->createTable('projects', true);
    }

    public function down()
    {
        $this->forge->dropTable('projects', true);
    }
}
