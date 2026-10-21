<?php

use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\AgoraTokenController;
use App\Http\Controllers\Public\AttendeeDashboardController;
use App\Http\Controllers\Public\ChatController;
use App\Http\Controllers\Public\ConfirmRegistrationController;
use App\Http\Controllers\Public\FeedbackController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LegalController;
use App\Http\Controllers\Public\LiveController;
use App\Http\Controllers\Public\NetworkingController;
use App\Http\Controllers\Public\NotificationController;
use App\Http\Controllers\Public\PartnerPageController;
use App\Http\Controllers\Public\PaymentController;
use App\Http\Controllers\Public\PollController as PublicPollController;
use App\Http\Controllers\Public\PreferencesController;
use App\Http\Controllers\Public\QuestionController as PublicQuestionController;
use App\Http\Controllers\Public\ReactionController;
use App\Http\Controllers\Public\RegistrationController;
use App\Http\Controllers\Public\RegistrationNotMeController;
use App\Http\Controllers\Public\ResourceController as PublicResourceController;
use App\Http\Controllers\Public\SpeakerConnectionController;
use App\Http\Controllers\Public\SpeakerReportController;
use App\Http\Controllers\Public\StageController;
use App\Http\Controllers\Public\SwitchToInPersonController;
use App\Http\Controllers\Public\TicketController;
use App\Http\Controllers\Public\VoucherRedemptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Privacy Policy and Terms & Conditions: public, and linked from every
// footer, the registration form and sign-up.
Route::get('/privacy', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [LegalController::class, 'terms'])->name('legal.terms');
Route::middleware('auth')->group(function () {
    Route::get('/legal/accept', [LegalController::class, 'showAccept'])->name('legal.accept');
    Route::post('/legal/accept', [LegalController::class, 'accept'])->name('legal.accept.store');
});

// Page heartbeat for "live now" analytics and the Live page's online
// count (resources/js/lib/presence.js). Guests too: sessions exist for all.
Route::middleware('throttle:presence')->group(function () {
    Route::post('/presence', [PresenceController::class, 'ping'])->name('presence.ping');
    Route::post('/presence/leave', [PresenceController::class, 'leave'])->name('presence.leave');
});

// Registration (Phase 2) — no auth required, may create an account.
// B2 — never auto-logs anyone in; POST /attend is throttled to blunt
// enumeration/spam (5/min/IP, 3/hour/normalised-email).
Route::get('/attend', [RegistrationController::class, 'create'])->name('event.register');
Route::post('/attend', [RegistrationController::class, 'store'])
    ->middleware('throttle:registration')->name('event.register.store');
Route::post('/attend/validate-step', [RegistrationController::class, 'validateStep'])
    ->middleware('throttle:registration-step')->name('event.register.validate-step');
Route::get('/attend/confirmation', [RegistrationController::class, 'confirmation'])
    ->name('event.register.confirmation');

// "This wasn't me": cancels a registration that was added to an existing
// account by someone who wasn't signed in. No login: the signed link in
// the email (14 days) is the credential.
Route::middleware(['signed', 'throttle:20,1'])->group(function () {
    Route::get('/registration-not-me/{registration}', [RegistrationNotMeController::class, 'show'])->name('registration.not-me');
    Route::post('/registration-not-me/{registration}', [RegistrationNotMeController::class, 'destroy'])->name('registration.not-me.cancel');
});
Route::get('/registration-cancelled', [RegistrationNotMeController::class, 'done'])->name('registration.not-me.done');

// Remote speaker stage (Broadcast console → stage link). No login: the
// unguessable link is the credential; StageController 404s anything else.
Route::middleware('throttle:stage')->group(function () {
    Route::get('/stage/{token}', [StageController::class, 'show'])->name('stage.show');
    Route::post('/stage/{token}/credentials', [StageController::class, 'credentials'])
        ->middleware('throttle:stage-credentials')->name('stage.credentials');

    // The speaker's presenter panel: polls, questions, chat — and real-time
    // channel authorisation, since speakers have no account.
    Route::get('/stage/{token}/live', [StageController::class, 'liveState'])->name('stage.live');
    Route::post('/stage/{token}/polls', [StageController::class, 'storePoll'])->name('stage.polls.store');
    Route::post('/stage/{token}/polls/{poll}/open', [StageController::class, 'openPoll'])->name('stage.polls.open');
    Route::post('/stage/{token}/polls/{poll}/close', [StageController::class, 'closePoll'])->name('stage.polls.close');
    Route::post('/stage/{token}/broadcasting/auth', [StageController::class, 'broadcastingAuth'])->name('stage.broadcasting-auth');
});

// A speaker's report: the people who chose to connect with them, plus a
// session report. No login: the emailed, expiring link is the credential.
Route::middleware('throttle:speaker-report')->group(function () {
    Route::get('/speaker-report/{token}', [SpeakerReportController::class, 'show'])->name('speaker-report.show');
    Route::get('/speaker-report/{token}/contacts.csv', [SpeakerReportController::class, 'contacts'])->name('speaker-report.contacts');
});

// B2 — destination of the ConfirmEmailAndSetPassword link. Guest-only:
// makes no sense once already authenticated.
Route::middleware('guest')->group(function () {
    Route::get('/confirm-registration/{token}', [ConfirmRegistrationController::class, 'create'])
        ->name('registration.confirm');
    Route::post('/confirm-registration', [ConfirmRegistrationController::class, 'store'])
        ->middleware('throttle:registration')->name('registration.confirm.store');
});

// Profile & password (the revived Breeze profile page) — `auth` only, not
// `verified`: someone who has just changed their email must still reach
// it to see the "confirm your new address" notice and resend the link.
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// B2 — `verified` applies to the whole attendee area, not just admin;
// MustVerifyEmail on User makes this a real gate now, not a silent no-op.
Route::middleware(['auth', 'verified', 'legal'])->group(function () {
    // Phase 3 — payment + ticket. Not gated by `attendee`: an in-person
    // registrant reaches these before/without full "registered" status
    // review, and PaymentController/TicketController scope to the user's
    // own registration directly.
    Route::get('/payment', [PaymentController::class, 'show'])->name('payment.show');
    // An online registrant moving to in person (an in-person-only event).
    Route::post('/registration/in-person', SwitchToInPersonController::class)
        ->middleware('throttle:voucher-redeem')->name('registration.switch-to-in-person');
    Route::post('/payment', [PaymentController::class, 'store'])->name('payment.store');
    Route::get('/ticket', [TicketController::class, 'show'])->name('ticket.show');
    Route::get('/ticket/pdf', [TicketController::class, 'pdf'])
        ->middleware('throttle:ticket-pdf')->name('ticket.pdf');

    // An invitation code redeemed by someone who is already registered
    // (or already has an account). GET only pre-fills the form — link
    // scanners prefetch emailed links, so only the POST uses a code up.
    Route::get('/redeem', [VoucherRedemptionController::class, 'show'])->name('voucher.redeem');
    Route::post('/redeem', [VoucherRedemptionController::class, 'store'])
        ->middleware('throttle:voucher-redeem')->name('voucher.redeem.store');

    // Phase 4 — attendee dashboard + preferences. Not gated by `attendee`:
    // a verified user with no registration still needs to reach the
    // dashboard to see the "you haven't registered yet" state
    // (Public/DashboardNoRegistration.vue) rather than being 403'd.
    Route::get('/dashboard', [AttendeeDashboardController::class, 'index'])->name('attendee.dashboard');
    Route::get('/account', [PreferencesController::class, 'edit'])->name('attendee.preferences.edit');
    Route::put('/account', [PreferencesController::class, 'update'])->name('attendee.preferences.update');
    Route::get('/account/download', [PreferencesController::class, 'download'])->name('attendee.preferences.download');
    // Withdrawing a speaker connection works for any past event, so it sits
    // outside the `attendee` gate (the connection must be the user's own).
    Route::delete('/account/speaker-connections/{speakerConnection}', [SpeakerConnectionController::class, 'destroy'])
        ->middleware('throttle:speaker-connect')->name('attendee.speaker-connections.destroy');
    Route::delete('/account/partner-connections/{partnerConnection}', [PartnerPageController::class, 'withdraw'])
        ->middleware('throttle:partner-connect')->name('attendee.partner-connections.destroy');
    Route::delete('/account', [PreferencesController::class, 'destroy'])->name('attendee.preferences.destroy');

    // D-Notifications — the AttendeeLayout bell.
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // B1 — every route below requires: verified email + a `registered`
    // EventRegistration for the resolved event + that event published.
    // Staff bypass. In-person attendees whose payment is still pending
    // are NOT blocked here (this middleware never checks payment status)
    // — they can watch/participate online while awaiting approval; only
    // physical check-in enforces ticket status, separately.
    Route::middleware('attendee')->group(function () {
        // Phase 5 — resources
        Route::get('/resources', [PublicResourceController::class, 'index'])->name('resources.index');
        Route::get('/resources/{resource}', [PublicResourceController::class, 'show'])->name('resources.show');

        // Phase 6 — live event
        Route::get('/live', [LiveController::class, 'show'])->name('live.show');
        Route::get('/live/agora-token/{event}', [AgoraTokenController::class, 'show'])
            ->middleware('throttle:agora-token')->name('live.agora-token');

        Route::post('/live/{event}/questions', [PublicQuestionController::class, 'store'])
            ->middleware('throttle:questions')->name('live.questions.store');
        Route::post('/live/questions/{question}/upvote', [PublicQuestionController::class, 'upvote'])
            ->middleware('throttle:upvote')->name('live.questions.upvote');

        Route::post('/live/polls/{poll}/options/{option}', [PublicPollController::class, 'respond'])
            ->middleware('throttle:poll-respond')->name('live.polls.respond');

        Route::post('/live/{event}/reactions', [ReactionController::class, 'store'])
            ->middleware('throttle:reactions')->name('live.reactions.store');

        Route::post('/live/{event}/chat', [ChatController::class, 'store'])
            ->middleware('throttle:chat')->name('live.chat.store');

        // Phase 7 — networking
        Route::get('/networking', [NetworkingController::class, 'index'])->name('networking.index');
        Route::post('/networking/{registration:public_id}/request', [NetworkingController::class, 'request'])
            ->middleware('throttle:networking')->name('networking.request');
        Route::post('/networking/connections/{connection}/accept', [NetworkingController::class, 'accept'])
            ->middleware('throttle:connection-response')->name('networking.accept');
        Route::post('/networking/connections/{connection}/decline', [NetworkingController::class, 'decline'])
            ->middleware('throttle:connection-response')->name('networking.decline');
        Route::delete('/networking/connections/{connection}/withdraw', [NetworkingController::class, 'withdraw'])
            ->middleware('throttle:connection-response')->name('networking.withdraw');
        Route::delete('/networking/connections/{connection}', [NetworkingController::class, 'remove'])
            ->middleware('throttle:connection-response')->name('networking.remove');

        // Connect with a speaker: the attendee chooses to share their
        // details with one speaker (the {event} param is gated above).
        Route::post('/events/{event}/speakers/{speaker}/connect', [SpeakerConnectionController::class, 'store'])
            ->middleware('throttle:speaker-connect')->name('speakers.connect');

        // A partner's page, and the attendee's two ways to become its lead:
        // Connect, or an enquiry. Both record the partner_contact consent.
        Route::get('/events/{event}/partners/{partner}', [PartnerPageController::class, 'show'])->name('partners.show');
        Route::post('/events/{event}/partners/{partner}/connect', [PartnerPageController::class, 'connect'])
            ->middleware('throttle:partner-connect')->name('partners.connect');
        Route::post('/events/{event}/partners/{partner}/enquiry', [PartnerPageController::class, 'enquire'])
            ->middleware('throttle:partner-enquiry')->name('partners.enquire');

        // Phase 8 — feedback
        Route::get('/feedback', [FeedbackController::class, 'create'])->name('feedback.create');
        Route::post('/feedback', [FeedbackController::class, 'store'])
            ->middleware('throttle:feedback')->name('feedback.store');
        Route::get('/feedback/thanks', [FeedbackController::class, 'thanks'])->name('feedback.thanks');
    });
});

require __DIR__.'/auth.php';
