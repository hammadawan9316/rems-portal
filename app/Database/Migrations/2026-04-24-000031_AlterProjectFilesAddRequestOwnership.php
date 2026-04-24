<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterProjectFilesAddRequestOwnership extends Migration
{
    public function up()
    {
        if ($this->foreignKeyExists('project_files', 'fk_project_files_project_id')) {
            $this->db->query('ALTER TABLE `project_files` DROP FOREIGN KEY `fk_project_files_project_id`');
        }

        if ($this->columnExists('project_files', 'project_id')) {
            $this->db->query('ALTER TABLE `project_files` MODIFY COLUMN `project_id` BIGINT UNSIGNED NULL');
        }

        if (!$this->columnExists('project_files', 'quotation_request_id')) {
            $this->db->query('ALTER TABLE `project_files` ADD COLUMN `quotation_request_id` BIGINT UNSIGNED NULL AFTER `project_id`');
        }

        if (!$this->columnExists('project_files', 'request_project_index')) {
            $this->db->query('ALTER TABLE `project_files` ADD COLUMN `request_project_index` INT UNSIGNED NULL AFTER `quotation_request_id`');
        }

        if (!$this->indexExists('project_files', 'idx_project_files_request_id')) {
            $this->db->query('ALTER TABLE `project_files` ADD INDEX `idx_project_files_request_id` (`quotation_request_id`)');
        }

        if (!$this->indexExists('project_files', 'idx_project_files_request_project_index')) {
            $this->db->query('ALTER TABLE `project_files` ADD INDEX `idx_project_files_request_project_index` (`request_project_index`)');
        }

        if ($this->columnExists('project_files', 'project_id') && !$this->foreignKeyExists('project_files', 'fk_project_files_project_id')) {
            $this->db->query('ALTER TABLE `project_files` ADD CONSTRAINT `fk_project_files_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
        }

        if ($this->columnExists('project_files', 'quotation_request_id') && !$this->foreignKeyExists('project_files', 'fk_project_files_request_id')) {
            $this->db->query('ALTER TABLE `project_files` ADD CONSTRAINT `fk_project_files_request_id` FOREIGN KEY (`quotation_request_id`) REFERENCES `quotation_requests`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
        }
    }

    public function down()
    {
        if ($this->foreignKeyExists('project_files', 'fk_project_files_request_id')) {
            $this->db->query('ALTER TABLE `project_files` DROP FOREIGN KEY `fk_project_files_request_id`');
        }

        if ($this->indexExists('project_files', 'idx_project_files_request_project_index')) {
            $this->db->query('ALTER TABLE `project_files` DROP INDEX `idx_project_files_request_project_index`');
        }

        if ($this->indexExists('project_files', 'idx_project_files_request_id')) {
            $this->db->query('ALTER TABLE `project_files` DROP INDEX `idx_project_files_request_id`');
        }

        if ($this->columnExists('project_files', 'request_project_index')) {
            $this->db->query('ALTER TABLE `project_files` DROP COLUMN `request_project_index`');
        }

        if ($this->columnExists('project_files', 'quotation_request_id')) {
            $this->db->query('ALTER TABLE `project_files` DROP COLUMN `quotation_request_id`');
        }

        if ($this->columnExists('project_files', 'project_id')) {
            $this->db->query('ALTER TABLE `project_files` MODIFY COLUMN `project_id` BIGINT UNSIGNED NOT NULL');
        }

        if (!$this->foreignKeyExists('project_files', 'fk_project_files_project_id')) {
            $this->db->query('ALTER TABLE `project_files` ADD CONSTRAINT `fk_project_files_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE ON UPDATE CASCADE');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();
        return $rows !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = $this->db->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $this->db->escape($index))->getResultArray();
        return $rows !== [];
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $rows = $this->db->query(
            'SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->db->escape($table) . ' AND CONSTRAINT_TYPE = "FOREIGN KEY" AND CONSTRAINT_NAME = ' . $this->db->escape($constraintName)
        )->getResultArray();

        return $rows !== [];
    }
}
