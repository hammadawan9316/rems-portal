<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterQuotationStatusAndProjectBillingColumns extends Migration
{
    public function up()
    {
        if ($this->columnExists('quotations', 'status')) {
            $this->db->query(
                "UPDATE `quotations`\n"
                . "SET `status` = CASE\n"
                . "    WHEN LOWER(TRIM(`status`)) = 'requested' THEN 'requested'\n"
                . "    WHEN LOWER(TRIM(`status`)) = 'draft' THEN 'draft'\n"
                . "    WHEN LOWER(TRIM(`status`)) = 'pending' THEN 'pending'\n"
                . "    WHEN LOWER(TRIM(`status`)) = 'accepted' THEN 'accepted'\n"
                . "    WHEN LOWER(TRIM(`status`)) = 'rejected' THEN 'rejected'\n"
                . "    WHEN LOWER(TRIM(`status`)) = 'completed' THEN 'completed'\n"
                . "    WHEN LOWER(TRIM(`status`)) = 'square_failed' THEN 'square_failed'\n"
                . "    ELSE 'pending'\n"
                . "END"
            );

            $this->db->query("ALTER TABLE `quotations` MODIFY COLUMN `status` ENUM('requested','draft','pending','accepted','rejected','completed','square_failed') NOT NULL DEFAULT 'draft'");
        }

        if ($this->columnExists('projects', 'estimated_amount')) {
            $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `estimated_amount` DECIMAL(13,2) NULL');
        }

        if ($this->columnExists('projects', 'payment_type')) {
            $this->db->query(
                "UPDATE `projects`\n"
                . "SET `payment_type` = CASE\n"
                . "    WHEN LOWER(TRIM(`payment_type`)) IN ('hourly', 'hourly_rate', 'hourly-rate', 'hourlyrate') THEN 'hourly'\n"
                . "    WHEN LOWER(TRIM(`payment_type`)) IN ('fixed_rate', 'fixed', 'fixed-rate', 'fixedrate') THEN 'fixed_rate'\n"
                . "    ELSE 'fixed_rate'\n"
                . "END"
            );

            $this->db->query("ALTER TABLE `projects` MODIFY COLUMN `payment_type` ENUM('fixed_rate','hourly') NOT NULL DEFAULT 'fixed_rate'");
        }
    }

    public function down()
    {
        if ($this->columnExists('projects', 'payment_type')) {
            $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `payment_type` VARCHAR(20) NULL');
        }

        if ($this->columnExists('projects', 'estimated_amount')) {
            $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `estimated_amount` INT UNSIGNED NULL');
        }

        if ($this->columnExists('quotations', 'status')) {
            $this->db->query("UPDATE `quotations` SET `status` = 'pending' WHERE `status` = 'draft'");
            $this->db->query("ALTER TABLE `quotations` MODIFY COLUMN `status` ENUM('requested','pending','accepted','rejected','completed','square_failed') NOT NULL DEFAULT 'requested'");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
