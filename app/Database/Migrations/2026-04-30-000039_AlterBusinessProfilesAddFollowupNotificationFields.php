<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterBusinessProfilesAddFollowupNotificationFields extends Migration
{
    public function up()
    {
        if (!$this->columnExists('business_profiles', 'followup_notification_days')) {
            $this->db->query('ALTER TABLE `business_profiles` ADD COLUMN `followup_notification_days` INT UNSIGNED NOT NULL DEFAULT 7 AFTER `website_url`');
        }

        if (!$this->columnExists('business_profiles', 'followup_notification_text')) {
            $this->db->query('ALTER TABLE `business_profiles` ADD COLUMN `followup_notification_text` TEXT NULL AFTER `followup_notification_days`');
        }
    }

    public function down()
    {
        if ($this->columnExists('business_profiles', 'followup_notification_text')) {
            $this->db->query('ALTER TABLE `business_profiles` DROP COLUMN `followup_notification_text`');
        }

        if ($this->columnExists('business_profiles', 'followup_notification_days')) {
            $this->db->query('ALTER TABLE `business_profiles` DROP COLUMN `followup_notification_days`');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $rows = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $rows !== [];
    }
}