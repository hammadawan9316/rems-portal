<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterClausesAddMetaTitle extends Migration
{
    public function up()
    {
        if ($this->columnExists('clauses', 'meta_title')) {
            return;
        }

        $this->db->query('ALTER TABLE `clauses` ADD COLUMN `meta_title` VARCHAR(190) NULL AFTER `title`');
    }

    public function down()
    {
        if (!$this->columnExists('clauses', 'meta_title')) {
            return;
        }

        $this->db->query('ALTER TABLE `clauses` DROP COLUMN `meta_title`');
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
