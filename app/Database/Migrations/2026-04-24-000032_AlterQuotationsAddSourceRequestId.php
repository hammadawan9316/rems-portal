<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationsAddSourceRequestId extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotations', 'source_request_id')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `source_request_id` BIGINT UNSIGNED NULL AFTER `customer_id`');
        }

        if (!$this->indexExists('quotations', 'idx_quotations_source_request_id')) {
            $this->db->query('ALTER TABLE `quotations` ADD UNIQUE KEY `idx_quotations_source_request_id` (`source_request_id`)');
        }

        if (!$this->foreignKeyExists('quotations', 'fk_quotations_source_request_id')) {
            $this->db->query('ALTER TABLE `quotations` ADD CONSTRAINT `fk_quotations_source_request_id` FOREIGN KEY (`source_request_id`) REFERENCES `quotation_requests`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
        }
    }

    public function down()
    {
        if ($this->foreignKeyExists('quotations', 'fk_quotations_source_request_id')) {
            $this->db->query('ALTER TABLE `quotations` DROP FOREIGN KEY `fk_quotations_source_request_id`');
        }

        if ($this->indexExists('quotations', 'idx_quotations_source_request_id')) {
            $this->db->query('ALTER TABLE `quotations` DROP INDEX `idx_quotations_source_request_id`');
        }

        if ($this->columnExists('quotations', 'source_request_id')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `source_request_id`');
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
