<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-Notifications — idempotency ledger for the scheduled sends
 * (reminders:send, postevent:send) so a slow/overlapping scheduler run
 * can never double-send the same notification type to the same user for
 * the same event. A generic ledger rather than a column per reminder
 * type, since the type list is expected to grow (Stage D adds ~6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('type');
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'event_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_notifications');
    }
};
