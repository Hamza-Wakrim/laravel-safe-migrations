<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use HamzaWakrim\SafeMigrations\Attributes\AllowUnsafe;

return new #[AllowUnsafe('drop-column', reason: 'legacy has been unused since v2')] class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('legacy');
        });
    }
};
