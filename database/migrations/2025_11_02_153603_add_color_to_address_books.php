<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('addressbooks', function (Blueprint $table) {
            $table->string('color', 7)->default('#0088CC');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('addressbooks', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
