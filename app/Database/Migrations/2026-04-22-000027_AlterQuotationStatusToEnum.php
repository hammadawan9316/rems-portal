<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationStatusToEnum extends Migration
{
    public function up()
    {
        if (!$this->columnExists('quotations', 'status')) {
            return;
        }

        $this->db->query(
            "UPDATE `quotations`\n"
            . "SET `status` = CASE\n"
            . "    WHEN `status` = 'requested' THEN 'requested'\n"
            . "    WHEN `status` = 'pending' THEN 'pending'\n"
            . "    WHEN `status` = 'accepted' THEN 'accepted'\n"
            . "    WHEN `status` = 'rejected' THEN 'rejected'\n"
            . "    WHEN `status` = 'completed' THEN 'completed'\n"
            . "    WHEN `status` = 'square_failed' THEN 'rejected'\n"
            . "    ELSE 'pending'\n"
            . "END"
        );

        $this->db->query("ALTER TABLE `quotations` MODIFY COLUMN `status` ENUM('requested','pending','accepted','rejected','completed') NOT NULL DEFAULT 'requested'");
    }

    public function down()
    {
        if (!$this->columnExists('quotations', 'status')) {
            return;
        }

        $this->db->query("ALTER TABLE `quotations` MODIFY COLUMN `status` VARCHAR(40) NOT NULL DEFAULT 'pending'");
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
