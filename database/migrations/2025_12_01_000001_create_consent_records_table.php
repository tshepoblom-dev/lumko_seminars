<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only consent ledger (B7). Rows are never updated or deleted;
     * attendee_consents remains the current-state projection read by the UI.
     */
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->string('type'); // networking | lumko_marketing | follow_up_request
            $table->string('action'); // granted | withdrawn
            $table->string('purpose_key');
            $table->text('purpose_text');
            $table->string('purpose_version');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('source'); // registration | account | feedback | admin
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'type']);
            $table->index(['event_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
