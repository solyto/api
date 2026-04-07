<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement("
            ALTER TABLE addressbooks
            ADD COLUMN color VARCHAR(7) DEFAULT '#0088CC',
            ADD COLUMN is_default BOOLEAN DEFAULT FALSE
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement("
            ALTER TABLE addressbooks
            DROP COLUMN color,
            DROP COLUMN is_default
        ");
    }
};
