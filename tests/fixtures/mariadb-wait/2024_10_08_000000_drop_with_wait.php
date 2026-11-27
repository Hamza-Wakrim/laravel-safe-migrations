<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users WAIT 5 DROP COLUMN legacy');
        DB::statement('SET STATEMENT lock_wait_timeout=5 FOR ALTER TABLE users DROP COLUMN name');
    }

    public function down(): void {}
};
