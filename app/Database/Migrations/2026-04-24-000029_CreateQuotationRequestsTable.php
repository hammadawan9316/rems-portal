<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuotationRequestsTable extends Migration
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
            'request_number' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['requested', 'quoted', 'rejected', 'archived'],
                'default' => 'requested',
            ],
            'client_name' => [
                'type' => 'VARCHAR',
                'constraint' => 160,
                'null' => true,
            ],
            'client_email' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'client_phone' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => true,
            ],
            'company' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'payload_snapshot' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'quoted_at' => [
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
        $this->forge->addUniqueKey('request_number');
        $this->forge->addKey('customer_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'SET NULL', 'CASCADE', 'fk_quotation_requests_customer_id');
        $this->forge->createTable('quotation_requests', true);
    }

    public function down()
    {
        $this->forge->dropTable('quotation_requests', true);
    }
}
