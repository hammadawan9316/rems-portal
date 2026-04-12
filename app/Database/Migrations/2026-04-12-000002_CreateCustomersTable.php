<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates customers table for the normalized customer model.
 */
class CreateCustomersTableAndLinkProjects extends Migration
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
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 160,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
            ],
            'phone' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'company' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'square_customer_id' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
                'after' => 'company',
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
        $this->forge->addUniqueKey('email');
        $this->forge->addUniqueKey('square_customer_id');
        $this->forge->createTable('customers', true);
    }

    public function down()
    {
        $this->forge->dropTable('customers', true);
    }
}
