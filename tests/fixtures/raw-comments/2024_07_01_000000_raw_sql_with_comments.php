<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Remove the old column
            ALTER TABLE users DROP COLUMN legacy;
            /* uppercase every name */ UPDATE users SET name = upper(name);
            -- DROP TABLE users;
            SQL);
    }
};
