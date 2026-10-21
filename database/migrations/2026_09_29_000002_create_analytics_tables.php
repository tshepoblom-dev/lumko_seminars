<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site analytics, cookieless.
 *
 * page_views: one row per page hit. `visitor_hash` is a daily-rotating
 * hash of IP + user agent (App\Support\VisitorHash), so a visitor can be
 * counted once per day but never followed from one day to the next, and
 * no IP address or user id is stored.
 *
 * live_presences: one row per browser session, refreshed by the page's
 * heartbeat; "live now" is every row seen in the last 90 seconds. Rows are
 * pruned hourly (analytics:prune), so user_id here is short-lived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->date('viewed_on');
            $table->timestamp('viewed_at');
            $table->string('path');
            $table->string('route_name')->nullable();
            $table->char('visitor_hash', 64);
            $table->boolean('is_authenticated')->default(false);
            $table->string('referrer_host')->nullable();

            $table->index(['viewed_on', 'visitor_hash']);
            $table->index(['viewed_on', 'path']);
        });

        Schema::create('live_presences', function (Blueprint $table) {
            $table->id();
            $table->char('session_hash', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->cascadeOnDelete();
            $table->string('route_name')->nullable();
            $table->string('path');
            $table->timestamp('last_seen_at');

            $table->index('last_seen_at');
            $table->index(['event_id', 'route_name', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_presences');
        Schema::dropIfExists('page_views');
    }
};
