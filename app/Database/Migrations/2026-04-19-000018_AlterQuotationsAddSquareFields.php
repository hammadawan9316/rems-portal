<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationsAddSquareFields extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotations', 'square_order_id')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `square_order_id` VARCHAR(120) NULL AFTER `notes`');
        }

        if (!$this->columnExists('quotations', 'square_invoice_id')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `square_invoice_id` VARCHAR(120) NULL AFTER `square_order_id`');
        }

        if (!$this->columnExists('quotations', 'square_status')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `square_status` VARCHAR(40) NULL AFTER `square_invoice_id`');
        }

        if (!$this->columnExists('quotations', 'square_error')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `square_error` TEXT NULL AFTER `square_status`');
        }

        if (!$this->columnExists('quotations', 'square_synced_at')) {
            $this->db->query('ALTER TABLE `quotations` ADD COLUMN `square_synced_at` DATETIME NULL AFTER `square_error`');
        }
    }

    public function down()
    {
        if ($this->columnExists('quotations', 'square_synced_at')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `square_synced_at`');
        }

        if ($this->columnExists('quotations', 'square_error')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `square_error`');
        }

        if ($this->columnExists('quotations', 'square_status')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `square_status`');
        }

        if ($this->columnExists('quotations', 'square_invoice_id')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `square_invoice_id`');
        }

        if ($this->columnExists('quotations', 'square_order_id')) {
            $this->db->query('ALTER TABLE `quotations` DROP COLUMN `square_order_id`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
