<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(DB::getDriverName() === 'pgsql' ? "SET lock_timeout = '5s'" : 'SET SESSION lock_wait_timeout = 5');

        Schema::table('users', fn (Blueprint $table) => $table->text('bio')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('bio'));
    }
};
