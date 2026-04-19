<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateInvoicesTable extends Migration
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
            'quotation_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
            ],
            'project_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
            ],
            'invoice_number' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'default' => 'queued',
            ],
            'amount_cents' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'currency' => [
                'type' => 'VARCHAR',
                'constraint' => 12,
                'default' => 'USD',
            ],
            'square_order_id' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'square_invoice_id' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'square_status' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'issued_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'paid_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'square_error' => [
                'type' => 'TEXT',
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
        $this->forge->addUniqueKey('invoice_number');
        $this->forge->addKey('customer_id');
        $this->forge->addKey('quotation_id');
        $this->forge->addKey('project_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('customer_id', 'customers', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('quotation_id', 'quotations', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('project_id', 'projects', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('invoices', true);
    }

    public function down()
    {
        $this->forge->dropTable('invoices', true);
    }
}
