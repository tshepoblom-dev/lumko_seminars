<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speakers become one shared library that events opt into (event_speaker),
 * and a session can name several speakers (programme_session_speaker),
 * replacing the single programme_sessions.speaker_id.
 *
 * The link rows cascade: they carry no history of their own, so removing
 * an event or a speaker simply removes its links. (An event that has
 * sessions is still protected from deletion by the sessions FK and
 * EventDeletionGuard.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Read the old single-speaker links before touching the schema:
        // on SQLite, dropping a column rebuilds programme_sessions, and
        // that rebuild would cascade-delete rows already written to a
        // pivot that references it. So: read, drop, create, then write.
        $sessions = DB::table('programme_sessions')
            ->whereNotNull('speaker_id')
            ->orderBy('event_id')->orderBy('sort_order')->orderBy('start_time')
            ->get(['id', 'event_id', 'speaker_id']);

        Schema::table('programme_sessions', function (Blueprint $table) {
            $table->dropForeign(['speaker_id']);
            $table->dropColumn('speaker_id');
        });

        Schema::create('event_speaker', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('speaker_id')->constrained('speakers')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['event_id', 'speaker_id']);
        });

        Schema::create('programme_session_speaker', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_session_id')->constrained('programme_sessions')->cascadeOnDelete();
            $table->foreignId('speaker_id')->constrained('speakers')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['programme_session_id', 'speaker_id']);
        });

        // Backfill from the old single-speaker column.
        $now = now();

        $linked = [];     // "event:speaker" pairs already written
        $nextOrder = [];  // per event: next event_speaker.sort_order
        foreach ($sessions as $session) {
            DB::table('programme_session_speaker')->insert([
                'programme_session_id' => $session->id,
                'speaker_id' => $session->speaker_id,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Each speaker is linked to the event once, in the order they
            // first appear in its programme.
            $key = $session->event_id.':'.$session->speaker_id;
            if (! isset($linked[$key])) {
                $linked[$key] = true;
                $order = $nextOrder[$session->event_id] ?? 0;
                $nextOrder[$session->event_id] = $order + 1;
                DB::table('event_speaker')->insert([
                    'event_id' => $session->event_id,
                    'speaker_id' => $session->speaker_id,
                    'sort_order' => $order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only one speaker fits the old column: keep the first.
        $links = DB::table('programme_session_speaker')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['programme_session_id', 'speaker_id']);

        Schema::dropIfExists('programme_session_speaker');
        Schema::dropIfExists('event_speaker');

        Schema::table('programme_sessions', function (Blueprint $table) {
            $table->foreignId('speaker_id')->nullable()->after('event_id')->constrained('speakers')->nullOnDelete();
        });

        $done = [];
        foreach ($links as $link) {
            if (isset($done[$link->programme_session_id])) {
                continue;
            }
            $done[$link->programme_session_id] = true;
            DB::table('programme_sessions')->where('id', $link->programme_session_id)->update(['speaker_id' => $link->speaker_id]);
        }
    }
};
