<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterProjectsEstimatedAmountNullable extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('projects', [
            'estimated_amount' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => true,
                'default' => null,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('projects', [
            'estimated_amount' => [
                'type' => 'INT',
                'constraint' => 10,
                'unsigned' => true,
                'null' => false,
                'default' => 10000,
            ],
        ]);
    }
}
