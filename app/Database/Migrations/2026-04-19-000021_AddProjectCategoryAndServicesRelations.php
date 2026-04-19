<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddProjectCategoryAndServicesRelations extends Migration
{
    public function up()
    {
        if (!$this->columnExists('projects', 'category_id')) {
            $this->db->query('ALTER TABLE `projects` ADD COLUMN `category_id` INT UNSIGNED NULL AFTER `quotation_id`');
        }

        // Ensure type matches categories.id for foreign key compatibility.
        $this->db->query('ALTER TABLE `projects` MODIFY COLUMN `category_id` INT UNSIGNED NULL');

        // Backfill category_id from legacy nature values when possible.
        if ($this->columnExists('projects', 'nature')) {
            $this->db->query(
                'UPDATE `projects` p '
                . 'LEFT JOIN `categories` c_name ON LOWER(TRIM(c_name.`name`)) = LOWER(TRIM(p.`nature`)) '
                . 'LEFT JOIN `categories` c_slug ON LOWER(TRIM(c_slug.`slug`)) = LOWER(TRIM(p.`nature`)) '
                . 'SET p.`category_id` = COALESCE(c_name.`id`, c_slug.`id`) '
                . 'WHERE p.`category_id` IS NULL AND p.`nature` IS NOT NULL AND TRIM(p.`nature`) <> ""'
            );
        }

        if (!$this->indexExists('projects', 'idx_projects_category_id')) {
            $this->db->query('ALTER TABLE `projects` ADD INDEX `idx_projects_category_id` (`category_id`)');
        }

        if (!$this->foreignKeyExists('projects', 'fk_projects_category_id') && $this->tableExists('categories')) {
            $this->db->query(
                'ALTER TABLE `projects` '
                . 'ADD CONSTRAINT `fk_projects_category_id` '
                . 'FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) '
                . 'ON DELETE SET NULL ON UPDATE CASCADE'
            );
        }

        if (!$this->tableExists('project_services')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'BIGINT',
                    'constraint' => 20,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'project_id' => [
                    'type' => 'BIGINT',
                    'constraint' => 20,
                    'unsigned' => true,
                ],
                'service_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id', true);
            $this->forge->addKey('project_id');
            $this->forge->addKey('service_id');
            $this->forge->addUniqueKey(['project_id', 'service_id']);
            $this->forge->addForeignKey('project_id', 'projects', 'id', 'CASCADE', 'CASCADE', 'fk_project_services_project_id');
            $this->forge->addForeignKey('service_id', 'services', 'id', 'CASCADE', 'CASCADE', 'fk_project_services_service_id');
            $this->forge->createTable('project_services', true);
        }
    }

    public function down()
    {
        if ($this->tableExists('project_services')) {
            $this->forge->dropTable('project_services', true);
        }

        if ($this->foreignKeyExists('projects', 'fk_projects_category_id')) {
            $this->db->query('ALTER TABLE `projects` DROP FOREIGN KEY `fk_projects_category_id`');
        }

        if ($this->indexExists('projects', 'idx_projects_category_id')) {
            $this->db->query('ALTER TABLE `projects` DROP INDEX `idx_projects_category_id`');
        }

        if ($this->columnExists('projects', 'category_id')) {
            $this->db->query('ALTER TABLE `projects` DROP COLUMN `category_id`');
        }
    }

    private function tableExists(string $table): bool
    {
        $result = $this->db->query('SHOW TABLES LIKE ' . $this->db->escape($table))->getResultArray();

        return $result !== [];
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = $this->db->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->db->escape($column))->getResultArray();

        return $result !== [];
    }

    private function indexExists(string $table, string $index): bool
    {
        $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->db->escape($table) . ' AND INDEX_NAME = ' . $this->db->escape($index) . ' LIMIT 1';
        $result = $this->db->query($sql)->getResultArray();

        return $result !== [];
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ' . $this->db->escape($table) . ' AND CONSTRAINT_NAME = ' . $this->db->escape($constraint) . ' AND CONSTRAINT_TYPE = "FOREIGN KEY" LIMIT 1';
        $result = $this->db->query($sql)->getResultArray();

        return $result !== [];
    }
}
