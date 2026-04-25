<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationContractsAddRecipientSignedAt extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotation_contracts', 'owner_signed_at')) {
            $this->db->query('ALTER TABLE `quotation_contracts` ADD COLUMN `owner_signed_at` DATETIME NULL AFTER `owner_signature`');
        }

        if (!$this->columnExists('quotation_contracts', 'recipient_signed_at')) {
            $this->db->query('ALTER TABLE `quotation_contracts` ADD COLUMN `recipient_signed_at` DATETIME NULL AFTER `recipient_signature`');
        }
    }

    public function down()
    {
        if ($this->columnExists('quotation_contracts', 'owner_signed_at')) {
            $this->db->query('ALTER TABLE `quotation_contracts` DROP COLUMN `owner_signed_at`');
        }

        if ($this->columnExists('quotation_contracts', 'recipient_signed_at')) {
            $this->db->query('ALTER TABLE `quotation_contracts` DROP COLUMN `recipient_signed_at`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
