<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
        });

        Schema::connection('secondary')->create('audit_log', function (Blueprint $table) {
            $table->id();
        });

        Schema::connection('secondary')->table('events', function (Blueprint $table) {
            $table->dropColumn('legacy');
        });
    }
};
