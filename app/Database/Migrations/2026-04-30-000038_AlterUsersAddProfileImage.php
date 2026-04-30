<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

class AlterUsersAddProfileImage extends Migration
{
    protected $DBGroup = 'default';

    public function up()
    {
        $db = Database::connect();

        // Get fields safely
        $fields = $db->getFieldData('users');

        $columns = array_map(function ($field) {
            return $field->name;
        }, $fields);

        if (in_array('profile_image', $columns, true)) {
            return;
        }

        $this->forge->addColumn('users', [
            'profile_image' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'phone',
            ],
        ]);
    }

    public function down()
    {
        $db = Database::connect();

        $fields = $db->getFieldData('users');

        $columns = array_map(function ($field) {
            return $field->name;
        }, $fields);

        if (in_array('profile_image', $columns, true)) {
            $this->forge->dropColumn('users', 'profile_image');
        }
    }
}