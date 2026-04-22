<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterProjectsAndQuotationsDropDeprecatedColumns extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotations', 'description')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `description` TEXT NULL AFTER `quote_number`');
        }

        if ($this->columnExists('quotations', 'title') && $this->columnExists('quotations', 'description')) {
            $this->db->query('UPDATE `quotations` SET `description` = COALESCE(NULLIF(`description`, ""), `title`)');
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `title`');
        }

        if ($this->columnExists('projects', 'nature')) {
            $this->db->query('ALTER TABLE `projects` DROP COLUMN `nature`');
        }

        if ($this->columnExists('projects', 'trades')) {
            $this->db->query('ALTER TABLE `projects` DROP COLUMN `trades`');
        }
    }

    public function down()
    {
        if (!$this->columnExists('quotations', 'title')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `title` VARCHAR(190) NULL AFTER `quote_number`');
        }

        if ($this->columnExists('quotations', 'description') && $this->columnExists('quotations', 'title')) {
            $this->db->query('UPDATE `quotations` SET `title` = COALESCE(NULLIF(`title`, ""), LEFT(`description`, 190))');
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `description`');
        }

        if (!$this->columnExists('projects', 'nature')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `nature` VARCHAR(80) NULL AFTER `project_description`');
        }

        if (!$this->columnExists('projects', 'trades')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `trades` TEXT NULL AFTER `nature`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
