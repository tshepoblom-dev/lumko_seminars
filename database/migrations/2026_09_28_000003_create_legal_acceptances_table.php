<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who accepted which version of the Terms & Conditions and the Privacy
 * Policy, and when. Append-only like consent_records, but kept apart from
 * it: accepting the terms is agreeing to a contract, not a POPIA consent
 * that can be withdrawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('document', 20); // terms | privacy
            $table->string('version', 40);
            $table->string('source', 30); // registration | signup | prompt | seed
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at')->useCurrent();

            $table->index(['user_id', 'document', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
