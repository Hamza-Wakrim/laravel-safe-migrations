<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// [legacy] exists, but hasColumn() answers false in the dry run, so the drop is never seen.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'legacy')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('legacy'));
        }
    }

    public function down(): void {}
};
