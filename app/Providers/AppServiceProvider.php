<?php

namespace App\Providers;

use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Phase 6 — live event rate limits
        RateLimiter::for('questions', fn ($request) => Limit::perMinute(3)->by($request->user()->id));
        RateLimiter::for('reactions', fn ($request) => Limit::perMinute(20)->by($request->user()->id));
        // Every chat post reaches every viewer (docs/realtime-capacity.md).
        RateLimiter::for('chat', fn ($request) => Limit::perMinute(6)->by($request->user()->id));

        // B2 — the registration submit: a per-IP cap (seminar.registration.
        // ip_per_minute, default 30) AND 3/hour per normalised email, both
        // enforced (an attacker hitting from many IPs is still capped per
        // target email; one IP hammering many emails is still capped per IP).
        //
        // The stepper's per-step validation deliberately does NOT share
        // this limiter: one honest visitor makes 7 validate calls carrying
        // the same email, so a 3/hour-per-email cap locks them out on the
        // 4th "Next". Validation persists nothing and never reveals
        // whether an account exists, so a per-IP cap is enough.
        RateLimiter::for('registration-step', fn ($request) => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('registration', function ($request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(max(1, (int) config('seminar.registration.ip_per_minute', 30)))->by($request->ip()),
                Limit::perHour(3)->by('reg-email:'.($email !== '' ? $email : $request->ip())),
            ];
        });

        // B2/B9/B10/C4 — the remaining per-user action limiters named in
        // the remediation spec.
        RateLimiter::for('upvote', fn ($request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('poll-respond', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('networking', fn ($request) => Limit::perHour(20)->by($request->user()->id));
        RateLimiter::for('connection-response', fn ($request) => Limit::perMinute(30)->by($request->user()->id));
        RateLimiter::for('feedback', fn ($request) => Limit::perHour(5)->by($request->user()->id));
        RateLimiter::for('announcement-send', fn ($request) => Limit::perMinute(2)->by($request->user()->id));
        // Admin communications: a real send reaches many inboxes, a test
        // one address; both are capped so a stuck button can't flood them.
        RateLimiter::for('communication-send', fn ($request) => Limit::perHour(10)->by($request->user()->id));
        RateLimiter::for('communication-test', fn ($request) => Limit::perHour(20)->by($request->user()->id));
        // The page heartbeat (every 30s) plus a ping on each navigation.
        RateLimiter::for('presence', fn ($request) => Limit::perMinute(20)->by($request->session()->getId() ?: $request->ip()));
        RateLimiter::for('agora-token', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        // Remote speaker stage links carry no login, so these key on the
        // IP (and, for credentials, the link itself) rather than a user.
        RateLimiter::for('stage', fn ($request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('stage-credentials', fn ($request) => Limit::perMinute(10)->by($request->ip().'|'.hash('sha256', (string) $request->route('token'))));
        RateLimiter::for('setup-link', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        // Speaker connections: attendee connect/withdraw, and the no-login
        // report link (keyed on IP, like the stage link).
        RateLimiter::for('speaker-connect', fn ($request) => Limit::perMinute(20)->by($request->user()->id));
        RateLimiter::for('speaker-report', fn ($request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('speaker-report-send', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('attendee-export', fn ($request) => Limit::perMinute(5)->by($request->user()->id));
        // Invitation codes: redeeming one from an account, and admins
        // issuing/emailing them (each send reaches a real inbox).
        RateLimiter::for('voucher-redeem', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('voucher-send', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        // Each ticket PDF download renders a fresh PDF.
        RateLimiter::for('ticket-pdf', fn ($request) => Limit::perMinute(10)->by($request->user()->id));

        // Speaker and partner portals. The invitation page needs no login
        // (the emailed link is the credential), so it is keyed on IP.
        RateLimiter::for('portal-invitation', fn ($request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('portal-invite-send', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('portal-upload', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('greenroom-credentials', fn ($request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('portal-export', fn ($request) => Limit::perMinute(5)->by($request->user()->id));
        // Partner leads: an attendee connecting or enquiring, and a stand
        // rep scanning tickets (bounded so a scanner can't enumerate codes).
        RateLimiter::for('partner-connect', fn ($request) => Limit::perMinute(20)->by($request->user()->id));
        RateLimiter::for('partner-enquiry', fn ($request) => Limit::perHour(10)->by($request->user()->id));
        RateLimiter::for('partner-scan', fn ($request) => Limit::perMinute(30)->by($request->user()->id));

        // C1 — private-channel subscription auth; generous since a page
        // load subscribes once, but still bounded against a misbehaving
        // client retrying in a loop.
        RateLimiter::for('broadcasting-auth', fn ($request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // Every mail that leaves the app, with the SMTP server's message id,
        // so a "sent but never arrived" report can be traced (queue rows are
        // deleted on success, so without this there is no record either way).
        // A registration added to a confirmed account by someone who wasn't
        // signed in (RegistrationController::storeForExistingAccount) holds
        // its consents back until the owner signs in. Nobody else has
        // pending consents: an unconfirmed account's take effect when its
        // email is confirmed.
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User && $event->user->hasVerifiedEmail()) {
                app(ConsentService::class)->markPendingEffective($event->user);
            }
        });

        Event::listen(MessageSent::class, function (MessageSent $event) {
            Log::info('mail.sent', [
                'mailer' => config('mail.default'),
                'to' => array_map(fn ($address) => $address->getAddress(), $event->message->getTo()),
                'subject' => $event->message->getSubject(),
                'message_id' => $event->sent->getMessageId(),
            ]);
        });
    }
}
