<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterCustomersAddUserId extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE `customers` ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `square_customer_id`');
        $this->db->query('ALTER TABLE `customers` ADD UNIQUE KEY `uq_customers_user_id` (`user_id`)');
        $this->db->query('ALTER TABLE `customers` ADD CONSTRAINT `fk_customers_user_id` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE `customers` DROP FOREIGN KEY `fk_customers_user_id`');
        $this->db->query('ALTER TABLE `customers` DROP INDEX `uq_customers_user_id`');
        $this->db->query('ALTER TABLE `customers` DROP COLUMN `user_id`');
    }
}