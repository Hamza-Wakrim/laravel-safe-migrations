<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY users_email_index ON users (email)');
        DB::connection('testing')->table('users')->where('id', 1)->update(['name' => 'other connection']);
    }
};
