<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Speaker;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The emailed link to a speaker's report, modelled on the stage link
 * (LiveBroadcastController / StageController): the raw token exists only
 * in the email, event_speaker keeps its sha256 hash, and anything unknown,
 * revoked or expired is a plain 404 that confirms nothing.
 */
class SpeakerReportLink
{
    /** @return array{url: string, expires_at: CarbonInterface} */
    public static function issue(Event $event, Speaker $speaker): array
    {
        $token = Str::random(48);
        $expiresAt = now()->addDays(max(1, (int) config('seminar.speaker_report_days', 30)));

        DB::table('event_speaker')
            ->where('event_id', $event->id)
            ->where('speaker_id', $speaker->id)
            ->update([
                'report_token_hash' => hash('sha256', $token),
                'report_token_expires_at' => $expiresAt,
            ]);

        return ['url' => route('speaker-report.show', $token), 'expires_at' => $expiresAt];
    }

    /** Only once the email has actually gone. */
    public static function markSent(Event $event, Speaker $speaker): void
    {
        DB::table('event_speaker')
            ->where('event_id', $event->id)
            ->where('speaker_id', $speaker->id)
            ->update(['report_sent_at' => now()]);
    }

    public static function revoke(Event $event, Speaker $speaker): void
    {
        DB::table('event_speaker')
            ->where('event_id', $event->id)
            ->where('speaker_id', $speaker->id)
            ->update(['report_token_hash' => null, 'report_token_expires_at' => null]);
    }

    /** @return array{0: Event, 1: Speaker} */
    public static function resolve(string $token): array
    {
        $link = DB::table('event_speaker')
            ->where('report_token_hash', hash('sha256', $token))
            ->first(['event_id', 'speaker_id', 'report_token_expires_at']);

        abort_unless($link && $link->report_token_expires_at, 404);
        abort_if(Carbon::parse($link->report_token_expires_at)->isPast(), 404);

        $event = Event::find($link->event_id);
        $speaker = Speaker::find($link->speaker_id);

        abort_unless($event && $speaker, 404);

        return [$event, $speaker];
    }
}
