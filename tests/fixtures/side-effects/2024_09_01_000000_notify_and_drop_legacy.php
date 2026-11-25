<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Http::post('https://example.com/hooks/migration', ['event' => 'migrating']);
        Mail::raw('Migrating users', fn ($message) => $message->to('ops@example.com'));
        Notification::route('mail', 'ops@example.com')->notify(new class extends BaseNotification
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }
        });
        Queue::push('App\Jobs\RebuildSearchIndex');
        dispatch(function () {
            throw new RuntimeException('This job must not run during the check.');
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('legacy'));
    }
};
