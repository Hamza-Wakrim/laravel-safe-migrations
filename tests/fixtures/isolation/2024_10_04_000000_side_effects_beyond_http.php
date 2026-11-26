<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Storage::disk('local')->put('safe-migrations-check.txt', 'written by up()');
        Cache::put('safe-migrations-check', 'written by up()');
        cache()->store('array')->put('safe-migrations-helper', 'written by up()');
        event('safe-migrations.migrating');
        Process::run('touch '.escapeshellarg(sys_get_temp_dir().'/safe-migrations-process-ran'));
        Artisan::call('safe-migrations:test-touch');
    }

    public function down(): void {}
};
