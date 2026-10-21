<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin communications: one-off emails to everyone, one person, a picked
 * group or a filtered group, with attachments. `event_id` null means a
 * platform-wide send (super admins only, always marketing).
 *
 * communication_recipients is the delivery log: one row per person, and
 * its unique key means a person can never be queued twice for one send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->string('category'); // App\Enums\CommunicationCategory
            $table->string('recipient_mode'); // all | filtered | selected
            $table->json('filters')->nullable();
            $table->string('status')->default('sending'); // sending | sent | partially_failed | failed
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'created_at']);
        });

        Schema::create('communication_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_id')->constrained('communications')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });

        Schema::create('communication_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('communication_id')->constrained('communications')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('status')->default('queued'); // queued | sent | failed | skipped_no_consent
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['communication_id', 'user_id']);
            $table->index(['communication_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_recipients');
        Schema::dropIfExists('communication_attachments');
        Schema::dropIfExists('communications');
    }
};
