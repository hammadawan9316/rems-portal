<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationsAddPublicResponseFields extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotations', 'public_response_token_hash')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `public_response_token_hash` VARCHAR(64) NULL AFTER `notes`');
        }

        if (!$this->columnExists('quotations', 'public_response_token_issued_at')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `public_response_token_issued_at` DATETIME NULL AFTER `public_response_token_hash`');
        }

        if (!$this->columnExists('quotations', 'public_response_token_expires_at')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `public_response_token_expires_at` DATETIME NULL AFTER `public_response_token_issued_at`');
        }

        if (!$this->columnExists('quotations', 'public_response_token_used_at')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `public_response_token_used_at` DATETIME NULL AFTER `public_response_token_expires_at`');
        }

        if (!$this->columnExists('quotations', 'response_reason')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `response_reason` TEXT NULL AFTER `public_response_token_used_at`');
        }

        if (!$this->columnExists('quotations', 'response_actor')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `response_actor` VARCHAR(20) NULL AFTER `response_reason`');
        }

        if (!$this->columnExists('quotations', 'response_at')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `response_at` DATETIME NULL AFTER `response_actor`');
        }

        if (!$this->indexExists('quotations', 'idx_quotations_public_response_token_hash')) {
            $this->db->query('ALTER TABLE `quotations` ADD UNIQUE KEY `idx_quotations_public_response_token_hash` (`public_response_token_hash`)');
        }

        if (!$this->indexExists('quotations', 'idx_quotations_public_response_token_expires_at')) {
            $this->db->query('ALTER TABLE `quotations` ADD KEY `idx_quotations_public_response_token_expires_at` (`public_response_token_expires_at`)');
        }
    }

    public function down()
    {
        if ($this->indexExists('quotations', 'idx_quotations_public_response_token_hash')) {
            $this->db->query('ALTER TABLE `quotations` DROP INDEX `idx_quotations_public_response_token_hash`');
        }

        if ($this->indexExists('quotations', 'idx_quotations_public_response_token_expires_at')) {
            $this->db->query('ALTER TABLE `quotations` DROP INDEX `idx_quotations_public_response_token_expires_at`');
        }

        if ($this->columnExists('quotations', 'response_at')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `response_at`');
        }

        if ($this->columnExists('quotations', 'response_actor')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `response_actor`');
        }

        if ($this->columnExists('quotations', 'response_reason')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `response_reason`');
        }

        if ($this->columnExists('quotations', 'public_response_token_used_at')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `public_response_token_used_at`');
        }

        if ($this->columnExists('quotations', 'public_response_token_expires_at')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `public_response_token_expires_at`');
        }

        if ($this->columnExists('quotations', 'public_response_token_issued_at')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `public_response_token_issued_at`');
        }

        if ($this->columnExists('quotations', 'public_response_token_hash')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `public_response_token_hash`');
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
}
