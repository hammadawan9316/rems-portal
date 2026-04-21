<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterProjectsDeadlineAndDeliveryFields extends Migration
{
    public function up()
    {
        if (!$this->columnExists('projects', 'deadline')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `deadline` VARCHAR(40) NULL AFTER `zip_code`');
        } else {
            $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `deadline` VARCHAR(40) NULL');
        }

        if (!$this->columnExists('projects', 'delivery_date')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `delivery_date` VARCHAR(40) NULL AFTER `deadline`');
        } else {
            $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `delivery_date` VARCHAR(40) NULL');
        }

        if ($this->columnExists('projects', 'deadline_date')) {
            $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `deadline_date` VARCHAR(40) NULL');
        }
    }

    public function down()
    {
        if ($this->columnExists('projects', 'deadline_date')) {
            $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `deadline_date` DATE NULL');
        }

        if ($this->columnExists('projects', 'delivery_date')) {
            $this->db->query('ALTER TABLE `projects` DROP COLUMN `delivery_date`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}