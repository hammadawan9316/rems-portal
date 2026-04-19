<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterProjectsAddQuotationId extends Migration
{
    public function up()
    {
        if ($this->columnExists('projects', 'quotation_id')) {
            return;
        }

        $this->db->query('ALTER TABLE `projects` ADD COLUMN `quotation_id` BIGINT UNSIGNED NULL AFTER `customer_id`');
        $this->db->query('ALTER TABLE `projects` ADD INDEX `idx_projects_quotation_id` (`quotation_id`)');
        $this->db->query('ALTER TABLE `projects` ADD CONSTRAINT `fk_projects_quotation_id` FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down()
    {
        if (!$this->columnExists('projects', 'quotation_id')) {
            return;
        }

        $this->db->query('ALTER TABLE `projects` DROP FOREIGN KEY `fk_projects_quotation_id`');
        $this->db->query('ALTER TABLE `projects` DROP INDEX `idx_projects_quotation_id`');
        $this->db->query('ALTER TABLE `projects` DROP COLUMN `quotation_id`');
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
