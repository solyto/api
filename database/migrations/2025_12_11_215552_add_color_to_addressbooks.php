<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
            DB::connection('pgsql')->statement("
                ALTER TABLE addressbooks
                ADD COLUMN color VARCHAR(7) DEFAULT '#0088CC',
                ADD COLUMN is_default BOOLEAN DEFAULT FALSE
            ");
        } else {
            // SQLite only allows adding one column per ALTER TABLE statement.
            DB::connection('pgsql')->statement("ALTER TABLE addressbooks ADD COLUMN color VARCHAR(7) DEFAULT '#0088CC'");
            DB::connection('pgsql')->statement('ALTER TABLE addressbooks ADD COLUMN is_default BOOLEAN DEFAULT 0');
        }
    }

    public function down(): void
    {
        if (DB::connection('pgsql')->getDriverName() === 'pgsql') {
            DB::connection('pgsql')->statement("
                ALTER TABLE addressbooks
                DROP COLUMN color,
                DROP COLUMN is_default
            ");
        } else {
            DB::connection('pgsql')->statement('ALTER TABLE addressbooks DROP COLUMN color');
            DB::connection('pgsql')->statement('ALTER TABLE addressbooks DROP COLUMN is_default');
        }
    }
};
