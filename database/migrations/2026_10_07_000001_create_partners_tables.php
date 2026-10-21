<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partners (businesses and organisations that support an event).
 *
 * - partners: one shared library across events, like speakers. The logo is
 *   shown publicly only once staff approve it (logo_approved), like
 *   speakers.photo_approved.
 * - partner_tiers: per event, admin-defined (Gold, Silver…). `features`
 *   is the list of App\Enums\PartnerFeature values the tier unlocks;
 *   comp_tickets caps the invitation codes a partner on the tier may issue.
 * - event_partner: a partner's involvement in one event, on one tier.
 * - partner_members: the user accounts that may use a partner's portal.
 *   Access is a relationship, never a users.role value, so one person can
 *   be an attendee, a speaker and a partner rep at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('website', 2048)->nullable();
            $table->text('description')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_alt')->nullable();
            $table->boolean('logo_approved')->default(false);
            $table->timestamps();
        });

        Schema::create('partner_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('features');
            $table->unsignedSmallInteger('comp_tickets')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });

        Schema::create('event_partner', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('partner_tier_id')->nullable()->constrained('partner_tiers')->nullOnDelete();
            $table->string('stand_label', 80)->nullable();
            $table->text('blurb')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'partner_id']);
        });

        Schema::create('partner_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16)->default('member'); // owner | member
            $table->timestamps();

            $table->unique(['partner_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_members');
        Schema::dropIfExists('event_partner');
        Schema::dropIfExists('partner_tiers');
        Schema::dropIfExists('partners');
    }
};
