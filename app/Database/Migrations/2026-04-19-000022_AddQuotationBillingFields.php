<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddQuotationBillingFields extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotations', 'payment_type')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `payment_type` VARCHAR(20) NULL AFTER `status`');
        }

        if (!$this->columnExists('quotations', 'hourly_hours')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `hourly_hours` DECIMAL(10,2) NULL AFTER `payment_type`');
        }

        if (!$this->columnExists('quotations', 'discount_type')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `discount_type` VARCHAR(20) NULL AFTER `hourly_hours`');
        }

        if (!$this->columnExists('quotations', 'discount_value')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `discount_value` DECIMAL(12,2) NULL AFTER `discount_type`');
        }

        if (!$this->columnExists('quotations', 'discount_scope')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `discount_scope` VARCHAR(40) NULL AFTER `discount_value`');
        }
    }

    public function down()
    {
        $columns = ['discount_scope', 'discount_value', 'discount_type', 'hourly_hours', 'payment_type'];

        foreach ($columns as $column) {
            if ($this->columnExists('quotations', $column)) {
                $this->db->query('ALTER TABLE `quotations` DROP COLUMN `' . $column . '`');
            }
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
