<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use BoogieFromZk\AgoraToken\RtcTokenBuilder2;

/**
 * The one place that knows how Agora uids are laid out on an event's
 * channel, and that builds the tokens for them. The attendee player
 * relies on these ranges to tell a presenter's camera from their screen
 * share, so they're sent to the browser rather than hard-coded there.
 *
 *   1 – 99,999                 staff host camera (the Laravel user id)
 *   100,000 + speaker id       remote speaker camera (stage link)
 *   camera uid + 500,000,000   that presenter's screen share
 *   1,000,000,000 – 2^31−1     audience (random per join)
 */
class AgoraStage
{
    public const SPEAKER_UID_BASE = 100_000;

    public const SCREEN_UID_OFFSET = 500_000_000;

    public const AUDIENCE_UID_MIN = 1_000_000_000;

    public const AUDIENCE_UID_MAX = 2_147_483_647;

    /**
     * Speaker tokens are short-lived and renewed through the stage link,
     * so revoking a link takes a connected speaker off within this long —
     * Agora has no client-side way to kick someone off a channel.
     */
    public const SPEAKER_TOKEN_TTL_MINUTES = 15;

    /**
     * The event's green room: a separate Agora channel where speakers (and
     * staff) test camera, mic and connection. Attendees' tokens are only
     * ever for the main channel, so nothing here can reach them. Agora
     * channel names are at most 64 bytes, hence the trim.
     */
    public static function greenRoomChannel(Event $event): string
    {
        return substr((string) $event->livestream_channel_name, 0, 53).'--greenroom';
    }

    public static function staffUid(User $user): int
    {
        abort_if($user->id >= self::SPEAKER_UID_BASE, 500, 'Staff user id is outside the reserved host uid range.');

        return $user->id;
    }

    public static function speakerUid(Speaker $speaker): int
    {
        return self::SPEAKER_UID_BASE + $speaker->id;
    }

    public static function screenUidFor(int $cameraUid): int
    {
        return $cameraUid + self::SCREEN_UID_OFFSET;
    }

    public static function isScreenUid(int $uid): bool
    {
        return $uid >= self::SCREEN_UID_OFFSET && $uid < self::AUDIENCE_UID_MIN;
    }

    /**
     * Layout hints for the browser: which uids are screen shares.
     *
     * @return array{screenUidOffset: int, audienceUidMin: int}
     */
    public static function layout(): array
    {
        return [
            'screenUidOffset' => self::SCREEN_UID_OFFSET,
            'audienceUidMin' => self::AUDIENCE_UID_MIN,
        ];
    }

    /**
     * Camera-uid → name for the event's linked speakers, so the player can
     * label their tiles. Staff host uids aren't listed; the player labels
     * those "Host".
     *
     * @return array<int, string>
     */
    public static function presenterNames(Event $event): array
    {
        return $event->speakers()->get(['speakers.id', 'speakers.name'])
            ->mapWithKeys(fn (Speaker $s) => [self::speakerUid($s) => $s->name])
            ->all();
    }

    /**
     * Two publisher tokens: one for the presenter's camera/mic client and
     * one for their screen-share client. Agora tokens are bound to a uid,
     * and the browser publishes the screen from a second client so the
     * camera can stay on air alongside it.
     */
    public static function publisherCredentials(Event $event, int $cameraUid, ?int $ttl = null, ?string $channel = null): array
    {
        [$appId, $certificate] = self::credentialsFor($event);
        $ttl ??= (int) config('services.agora.token_ttl', 3600);
        $channel ??= $event->livestream_channel_name;
        $screenUid = self::screenUidFor($cameraUid);

        $build = fn (int $uid) => self::checked(RtcTokenBuilder2::buildTokenWithUid(
            $appId,
            $certificate,
            $channel,
            $uid,
            RtcTokenBuilder2::ROLE_PUBLISHER,
            $ttl
        ));

        return [
            'appId' => $appId,
            'channel' => $channel,
            'uid' => $cameraUid,
            'token' => $build($cameraUid),
            'screenUid' => $screenUid,
            'screenToken' => $build($screenUid),
            'expiresAt' => now()->addSeconds($ttl)->toIso8601String(),
            ...self::layout(),
        ];
    }

    /**
     * C4 — a random per-join audience uid, never the Laravel user id:
     * reusing a uid across two tabs/devices kicks the earlier session off
     * the channel, and a user id would leak to every other subscriber.
     */
    public static function subscriberCredentials(Event $event, ?string $channel = null): array
    {
        [$appId, $certificate] = self::credentialsFor($event);
        $ttl = (int) config('services.agora.token_ttl', 3600);
        $uid = random_int(self::AUDIENCE_UID_MIN, self::AUDIENCE_UID_MAX);
        $channel ??= $event->livestream_channel_name;

        return [
            'appId' => $appId,
            'channel' => $channel,
            'uid' => $uid,
            'token' => self::checked(RtcTokenBuilder2::buildTokenWithUid(
                $appId,
                $certificate,
                $channel,
                $uid,
                RtcTokenBuilder2::ROLE_SUBSCRIBER,
                $ttl
            )),
            'expiresAt' => now()->addSeconds($ttl)->toIso8601String(),
            ...self::layout(),
        ];
    }

    /**
     * The token builder returns an empty string, rather than failing, when
     * the App ID or certificate isn't 32 hex characters — which would
     * otherwise surface only as a vague join error in every browser.
     */
    private static function checked(string $token): string
    {
        abort_if($token === '', 500, 'Agora is misconfigured: AGORA_APP_ID and AGORA_APP_CERTIFICATE must each be 32 hex characters.');

        return $token;
    }

    /** @return array{0: string, 1: string} */
    private static function credentialsFor(Event $event): array
    {
        abort_unless($event->livestream_provider?->value === 'agora', 404);
        abort_unless($event->livestream_channel_name, 422, 'This event has no Agora channel configured.');

        $appId = config('services.agora.app_id');
        $certificate = config('services.agora.app_certificate');
        abort_if(! $appId || ! $certificate, 500, 'Agora is not configured.');

        return [$appId, $certificate];
    }
}
