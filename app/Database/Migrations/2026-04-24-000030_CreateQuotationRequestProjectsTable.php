<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateQuotationRequestProjectsTable extends Migration
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
            'quotation_request_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'request_project_index' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'default' => 0,
            ],
            'category_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'project_title' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'project_description' => [
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
                'type' => 'DECIMAL',
                'constraint' => '13,2',
                'null' => true,
            ],
            'payment_type' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'fixed_rate',
            ],
            'hourly_hours' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => true,
            ],
            'service_ids_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'raw_payload' => [
                'type' => 'LONGTEXT',
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
        $this->forge->addKey('quotation_request_id');
        $this->forge->addKey('category_id');
        $this->forge->addKey(['quotation_request_id', 'request_project_index']);
        $this->forge->addForeignKey('quotation_request_id', 'quotation_requests', 'id', 'CASCADE', 'CASCADE', 'fk_qr_projects_request_id');
        $this->forge->addForeignKey('category_id', 'categories', 'id', 'CASCADE', 'SET NULL', 'fk_qr_projects_category_id');
        $this->forge->createTable('quotation_request_projects', true);
    }

    public function down()
    {
        $this->forge->dropTable('quotation_request_projects', true);
    }
}
