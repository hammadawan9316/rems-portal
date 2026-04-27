<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationsAddBusinessSnapshotFields extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotations', 'business_profile_id')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `business_profile_id` BIGINT UNSIGNED NULL AFTER `source_request_id`');
            $this->db->query('ALTER TABLE `quotations` ADD KEY `idx_quotations_business_profile_id` (`business_profile_id`)');
        }

        if (!$this->columnExists('quotations', 'business_name')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `business_name` VARCHAR(190) NULL AFTER `business_profile_id`');
        }

        if (!$this->columnExists('quotations', 'business_admin_name')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `business_admin_name` VARCHAR(190) NULL AFTER `business_name`');
        }

        if (!$this->columnExists('quotations', 'business_email')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `business_email` VARCHAR(190) NULL AFTER `business_admin_name`');
        }

        if (!$this->columnExists('quotations', 'business_phone')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `business_phone` VARCHAR(60) NULL AFTER `business_email`');
        }

        if (!$this->columnExists('quotations', 'business_address')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `business_address` TEXT NULL AFTER `business_phone`');
        }

        if (!$this->columnExists('quotations', 'business_website_url')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `business_website_url` VARCHAR(255) NULL AFTER `business_address`');
        }
    }

    public function down()
    {
        $columns = [
            'business_website_url',
            'business_address',
            'business_phone',
            'business_email',
            'business_admin_name',
            'business_name',
            'business_profile_id',
        ];

        foreach ($columns as $column) {
            if ($this->columnExists('quotations', $column)) {
                if ($column === 'business_profile_id') {
                    $this->dropIndexIfExists('quotations', 'idx_quotations_business_profile_id');
                }
                $this->db->query('ALTER TABLE `quotations` DROP COLUMN `' . $column . '`');
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        $rows = $this->db->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $this->db->escape($index))->getResultArray();
        if ($rows !== []) {
            $this->db->query('ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`');
        }
    }
}
