<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationsAddFollowupIndexes extends Migration
{
    public function up()
    {
        if (!$this->indexExists('quotations', 'idx_quotations_followup_response')) {
            $this->db->query('ALTER TABLE `quotations` ADD KEY `idx_quotations_followup_response` (`response_at`, `public_response_token_issued_at`)');
        }
    }

    public function down()
    {
        if ($this->indexExists('quotations', 'idx_quotations_followup_response')) {
            $this->db->query('ALTER TABLE `quotations` DROP INDEX `idx_quotations_followup_response`');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = $this->db->query('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ' . $this->db->escape($index))->getResultArray();

        return $rows !== [];
    }
}