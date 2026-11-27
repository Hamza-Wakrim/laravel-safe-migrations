<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MySQL rejects LOCK=NONE for a FULLTEXT index; MariaDB 11.2+ accepts it.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD FULLTEXT INDEX users_name_fulltext (name), LOCK=NONE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP INDEX users_name_fulltext');
    }
};
