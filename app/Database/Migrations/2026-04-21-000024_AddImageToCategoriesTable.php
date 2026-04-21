<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddImageToCategoriesTable extends Migration
{
    public function up()
    {
        if (!$this->columnExists('categories', 'image')) {
            $this->forge->addColumn('categories', [
                'image' => [
                    'type' => 'VARCHAR',
                    'constraint' => 190,
                    'null' => true,
                    'after' => 'description',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->columnExists('categories', 'image')) {
            $this->forge->dropColumn('categories', 'image');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $result !== [];
    }
}
