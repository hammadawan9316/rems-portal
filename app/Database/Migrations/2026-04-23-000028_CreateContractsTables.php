<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateContractsTables extends Migration
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
            'title' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'description' => [
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
        $this->forge->createTable('clauses', true);

        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'template_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'template_description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'contract_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'contract_description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'owner_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
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
        $this->forge->createTable('contracts', true);

        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'quotation_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'contract_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'owner_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'owner_signature' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'recipient_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'recipient_signature' => [
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
        $this->forge->addKey('quotation_id');
        $this->forge->addKey('contract_id');
        $this->forge->addForeignKey('quotation_id', 'quotations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('contract_id', 'contracts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('quotation_contracts', true);

        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'contract_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'clause_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'sort_order' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
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
        $this->forge->addKey('contract_id');
        $this->forge->addKey('clause_id');
        $this->forge->addForeignKey('contract_id', 'contracts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('clause_id', 'clauses', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('contract_clauses', true);

        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'quotation_contract_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'clause_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'sort_order' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
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
        $this->forge->addKey('quotation_contract_id');
        $this->forge->addKey('clause_id');
        $this->forge->addForeignKey('quotation_contract_id', 'quotation_contracts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('clause_id', 'clauses', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('quotation_contract_clauses', true);
    }

    public function down()
    {
        $this->forge->dropTable('quotation_contract_clauses', true);
        $this->forge->dropTable('quotation_contracts', true);
        $this->forge->dropTable('contract_clauses', true);
        $this->forge->dropTable('contracts', true);
        $this->forge->dropTable('clauses', true);
    }
}
