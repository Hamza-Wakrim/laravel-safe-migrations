<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Pretend mode returns no rows, so code that expects data fails during the dry run.
        $setting = DB::table('users')->first();

        if ($setting->name === 'x') {
            DB::table('users')->update(['name' => 'y']);
        }
    }
};
