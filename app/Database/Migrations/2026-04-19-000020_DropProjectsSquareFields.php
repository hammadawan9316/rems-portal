<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class DropProjectsSquareFields extends Migration
{
    public function up()
    {
        $columns = [
            'square_order_id',
            'square_estimate_id',
            'square_error',
            'square_sync_attempts',
            'square_sync_queued_at',
            'square_synced_at',
        ];

        foreach ($columns as $column) {
            if ($this->columnExists('projects', $column)) {
                $this->db->query('ALTER TABLE `projects` DROP COLUMN `' . $column . '`');
            }
        }
    }

    public function down()
    {
        if (!$this->columnExists('projects', 'square_order_id')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `square_order_id` VARCHAR(80) NULL AFTER `status`');
        }

        if (!$this->columnExists('projects', 'square_estimate_id')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `square_estimate_id` VARCHAR(80) NULL AFTER `square_order_id`');
        }

        if (!$this->columnExists('projects', 'square_error')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `square_error` TEXT NULL AFTER `square_estimate_id`');
        }

        if (!$this->columnExists('projects', 'square_sync_attempts')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `square_sync_attempts` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `square_error`');
        }

        if (!$this->columnExists('projects', 'square_sync_queued_at')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `square_sync_queued_at` DATETIME NULL AFTER `square_sync_attempts`');
        }

        if (!$this->columnExists('projects', 'square_synced_at')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `square_synced_at` DATETIME NULL AFTER `square_sync_queued_at`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}
