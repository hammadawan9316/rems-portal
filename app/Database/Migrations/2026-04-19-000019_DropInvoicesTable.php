<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class DropInvoicesTable extends Migration
{
    public function up()
    {
        $this->forge->dropTable('invoices', true, true);
    }

    public function down()
    {
        // Intentionally left empty because invoice storage is no longer local.
    }
}
