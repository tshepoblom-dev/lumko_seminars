<?php

use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AttendeeController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CheckInController;
use App\Http\Controllers\Admin\ClientConversionController;
use App\Http\Controllers\Admin\CommunicationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EngagementController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\EventPartnerController;
use App\Http\Controllers\Admin\EventSpeakerController;
use App\Http\Controllers\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\Admin\LegacyEventRedirectController;
use App\Http\Controllers\Admin\LiveBroadcastController;
use App\Http\Controllers\Admin\LiveControlController;
use App\Http\Controllers\Admin\PartnerController;
use App\Http\Controllers\Admin\PartnerPortalAccessController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PlatformCommunicationController;
use App\Http\Controllers\Admin\PollController as AdminPollController;
use App\Http\Controllers\Admin\ProgrammeSessionController;
use App\Http\Controllers\Admin\QuestionController as AdminQuestionController;
use App\Http\Controllers\Admin\RegistrationQuestionController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\SpeakerController;
use App\Http\Controllers\Admin\SpeakerPortalAccessController;
use App\Http\Controllers\Admin\SpeakerReportController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Middleware\ShareEventWorkspace;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:super_admin,event_manager', 'legal'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Cross-event areas: the event list itself, the shared speaker
        // library, and the audit log.
        Route::resource('events', EventController::class)->except('show');
        Route::resource('speakers', SpeakerController::class)->except('show');
        // Speaker portal access: invite (emailed, single-use link) or remove.
        Route::post('speakers/{speaker}/portal-invite', [SpeakerPortalAccessController::class, 'invite'])
            ->middleware('throttle:portal-invite-send')->name('speakers.portal-invite');
        Route::delete('speakers/{speaker}/portal-access', [SpeakerPortalAccessController::class, 'revoke'])->name('speakers.portal-revoke');
        // The partner library (businesses that support events), like speakers.
        Route::resource('partners', PartnerController::class)->except('show');
        // Partner portal access: team invitations and members, from staff's side.
        Route::post('partners/{partner}/invitations', [PartnerPortalAccessController::class, 'invite'])
            ->middleware('throttle:portal-invite-send')->name('partners.invitations.store');
        Route::post('partners/{partner}/invitations/{invitation}/resend', [PartnerPortalAccessController::class, 'resend'])
            ->middleware('throttle:portal-invite-send')->name('partners.invitations.resend');
        Route::delete('partners/{partner}/invitations/{invitation}', [PartnerPortalAccessController::class, 'cancel'])->name('partners.invitations.cancel');
        Route::delete('partners/{partner}/members/{user}', [PartnerPortalAccessController::class, 'removeMember'])->name('partners.members.remove');
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        // Site traffic: daily visitors, page hits, who's online now.
        Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

        // Platform-wide emails (every attendee account, across events).
        // Super admins only: an event manager's reach stops at their events.
        Route::prefix('communications')->name('platform-communications.')->middleware('role:super_admin')->group(function () {
            Route::get('/', [PlatformCommunicationController::class, 'index'])->name('index');
            Route::get('create', [PlatformCommunicationController::class, 'create'])->name('create');
            Route::post('/', [PlatformCommunicationController::class, 'store'])
                ->middleware('throttle:communication-send')->name('store');
            Route::post('test', [PlatformCommunicationController::class, 'test'])
                ->middleware('throttle:communication-test')->name('test');
            Route::get('{communication}', [PlatformCommunicationController::class, 'show'])->name('show');
            Route::get('{communication}/attachments/{attachment}', [PlatformCommunicationController::class, 'attachment'])
                ->scopeBindings()->name('attachment');
        });

        // Everything that belongs to one event lives in that event's
        // workspace (AdminLayout + EventWorkspaceNav). Child records are
        // resolved through the event (scoped bindings), so a record from
        // another event is a 404 here, not a cross-event edit.
        Route::prefix('events/{event}')->middleware(ShareEventWorkspace::class)->scopeBindings()->group(function () {
            Route::get('/', [EventController::class, 'show'])->name('events.show');
            Route::put('livestream', [EventController::class, 'updateLivestream'])->name('events.livestream.update');
            Route::post('archive', [EventController::class, 'archive'])->name('events.archive');

            // Programme
            Route::resource('sessions', ProgrammeSessionController::class)
                ->except('show')
                ->parameters(['sessions' => 'programmeSession'])
                ->names('programme-sessions');

            // Speakers linked to this event (from the shared library)
            Route::get('speakers', [EventSpeakerController::class, 'index'])->name('events.speakers.index');
            Route::post('speakers', [EventSpeakerController::class, 'store'])->name('events.speakers.store');
            Route::post('speakers/{speaker}/move', [EventSpeakerController::class, 'move'])->name('events.speakers.move');
            Route::delete('speakers/{speaker}', [EventSpeakerController::class, 'destroy'])->name('events.speakers.destroy');
            // Invite the whole line-up to the speaker portal (those not yet invited)
            Route::post('speakers/portal-invite-all', [SpeakerPortalAccessController::class, 'inviteAll'])
                ->middleware('throttle:portal-invite-send')->name('events.speakers.portal-invite-all');
            // A speaker's report: the attendees who chose to connect + their session report
            Route::post('speakers/{speaker}/report/send', [SpeakerReportController::class, 'send'])
                ->middleware('throttle:speaker-report-send')->name('events.speakers.report.send');
            Route::post('speakers/{speaker}/report/revoke', [SpeakerReportController::class, 'revoke'])->name('events.speakers.report.revoke');
            Route::get('speakers/{speaker}/report', [SpeakerReportController::class, 'preview'])->name('events.speakers.report.preview');

            // The event's own registration questions (steps 4 and 5 of /attend)
            Route::prefix('registration-form')->name('events.registration-form.')->group(function () {
                Route::get('/', [RegistrationQuestionController::class, 'index'])->name('index');
                Route::get('preview', [RegistrationQuestionController::class, 'preview'])->name('preview');
                Route::put('titles', [RegistrationQuestionController::class, 'updatePageTitles'])->name('titles');
                Route::post('copy', [RegistrationQuestionController::class, 'copyFrom'])->name('copy');
                Route::post('questions', [RegistrationQuestionController::class, 'store'])->name('questions.store');
                Route::put('questions/{registrationQuestion}', [RegistrationQuestionController::class, 'update'])->name('questions.update');
                Route::post('questions/{registrationQuestion}/move', [RegistrationQuestionController::class, 'move'])->name('questions.move');
                Route::post('questions/{registrationQuestion}/toggle', [RegistrationQuestionController::class, 'toggle'])->name('questions.toggle');
                Route::delete('questions/{registrationQuestion}', [RegistrationQuestionController::class, 'destroy'])->name('questions.destroy');
            });
            // A file an attendee uploaded with their registration (staff only, audited)
            Route::get('attendees/{registration}/answers/{answer}/file', [AttendeeController::class, 'answerFile'])->name('attendees.answers.file');

            // Partners on this event: tiers, line-up, publishing, portal invitations
            Route::get('partners', [EventPartnerController::class, 'index'])->name('events.partners.index');
            Route::post('partners', [EventPartnerController::class, 'attach'])->name('events.partners.attach');
            Route::patch('partners/{eventPartner}', [EventPartnerController::class, 'update'])->name('events.partners.update');
            Route::delete('partners/{eventPartner}', [EventPartnerController::class, 'detach'])->name('events.partners.detach');
            Route::post('partners/{eventPartner}/invite', [EventPartnerController::class, 'invite'])
                ->middleware('throttle:portal-invite-send')->name('events.partners.invite');
            Route::post('partners-invite-owners', [PartnerPortalAccessController::class, 'inviteOwners'])
                ->middleware('throttle:portal-invite-send')->name('events.partners.invite-owners');
            Route::post('partner-tiers', [EventPartnerController::class, 'storeTier'])->name('events.partner-tiers.store');
            Route::put('partner-tiers/{partnerTier}', [EventPartnerController::class, 'updateTier'])->name('events.partner-tiers.update');
            Route::delete('partner-tiers/{partnerTier}', [EventPartnerController::class, 'destroyTier'])->name('events.partner-tiers.destroy');

            // Attendees (spec §21) + account-setup links
            Route::get('attendees', [AttendeeController::class, 'index'])->name('attendees.index');
            Route::get('attendees/export', [AttendeeController::class, 'export'])
                ->middleware('throttle:attendee-export')->name('attendees.export');
            Route::post('attendees/setup-links', [AttendeeController::class, 'sendSetupLinks'])
                ->middleware('throttle:setup-link')->name('attendees.setup-links');
            // People with an account who haven't registered for this event.
            Route::post('attendees/accounts/remind-all', [AttendeeController::class, 'remindAllToRegister'])
                ->middleware('throttle:setup-link')->name('attendees.accounts.remind-all');
            // {user} is an account, not something the event owns: the
            // controller checks it has no registration for this event.
            Route::post('attendees/accounts/{user}/remind', [AttendeeController::class, 'remindToRegister'])
                ->withoutScopedBindings()->middleware('throttle:setup-link')->name('attendees.accounts.remind');
            Route::get('attendees/{registration}', [AttendeeController::class, 'show'])->name('attendees.show');
            Route::post('attendees/{registration}/setup-link', [AttendeeController::class, 'sendSetupLink'])
                ->middleware('throttle:setup-link')->name('attendees.setup-link');
            // "Became a client", crediting the referring partner
            Route::put('attendees/{registration}/conversion', [ClientConversionController::class, 'update'])->name('attendees.conversion.update');
            Route::delete('attendees/{registration}/conversion', [ClientConversionController::class, 'destroy'])->name('attendees.conversion.destroy');

            // Payments + check-in
            Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
            Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
            Route::get('payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');
            Route::post('payments/{payment}/approve', [PaymentController::class, 'approve'])->name('payments.approve');
            Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject');
            Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');

            Route::get('check-in', [CheckInController::class, 'index'])->name('check-in.index');
            Route::post('check-in', [CheckInController::class, 'store'])->name('check-in.store');

            // Vouchers: honorary invitation codes (a complimentary in-person
            // ticket each). Revoked, never deleted.
            Route::get('vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
            Route::post('vouchers', [VoucherController::class, 'store'])
                ->middleware('throttle:voucher-send')->name('vouchers.store');
            Route::post('vouchers/{voucher}/send', [VoucherController::class, 'send'])
                ->middleware('throttle:voucher-send')->name('vouchers.send');
            Route::post('vouchers/{voucher}/revoke', [VoucherController::class, 'revoke'])->name('vouchers.revoke');

            // Announcements
            Route::resource('announcements', AnnouncementController::class)->except('show');
            Route::post('announcements/{announcement}/send-email', [AnnouncementController::class, 'sendEmail'])
                ->middleware('throttle:announcement-send')->name('announcements.send-email');

            // Communications: email to everyone, a filtered group or picked people
            Route::get('communications', [CommunicationController::class, 'index'])->name('communications.index');
            Route::get('communications/create', [CommunicationController::class, 'create'])->name('communications.create');
            Route::post('communications', [CommunicationController::class, 'store'])
                ->middleware('throttle:communication-send')->name('communications.store');
            Route::post('communications/test', [CommunicationController::class, 'test'])
                ->middleware('throttle:communication-test')->name('communications.test');
            Route::get('communications/{communication}', [CommunicationController::class, 'show'])->name('communications.show');
            Route::get('communications/{communication}/attachments/{attachment}', [CommunicationController::class, 'attachment'])
                ->name('communications.attachment');

            // Resources
            Route::resource('resources', ResourceController::class)->except('show');
            Route::post('resources/{resource}/publish', [ResourceController::class, 'publish'])->name('resources.publish');
            Route::post('resources/{resource}/unpublish', [ResourceController::class, 'unpublish'])->name('resources.unpublish');

            // Live event
            Route::prefix('live')->name('live.')->group(function () {
                Route::get('control-room', [LiveControlController::class, 'index'])->name('control-room');
                Route::get('online', [LiveControlController::class, 'online'])->name('online');
                Route::post('control-room/go-live', [LiveControlController::class, 'goLive'])->name('go-live');
                Route::post('control-room/end-live', [LiveControlController::class, 'endLive'])->name('end-live');
                Route::post('control-room/player/open', [LiveControlController::class, 'openPlayer'])->name('player.open');
                Route::post('control-room/player/close', [LiveControlController::class, 'closePlayer'])->name('player.close');
                Route::post('control-room/sessions/{programmeSession}', [LiveControlController::class, 'setCurrentSession'])->name('set-session');
                Route::post('control-room/sessions/{programmeSession}/start', [LiveControlController::class, 'startSession'])->name('sessions.start');
                Route::post('control-room/sessions/{programmeSession}/end', [LiveControlController::class, 'endSession'])->name('sessions.end');

                // C5 — Agora host/publisher console
                Route::get('broadcast', [LiveBroadcastController::class, 'show'])->name('broadcast.show');
                Route::post('broadcast/token', [LiveBroadcastController::class, 'token'])
                    ->middleware('throttle:agora-token')->name('broadcast.token');
                Route::post('broadcast/start', [LiveBroadcastController::class, 'start'])->name('broadcast.start');
                Route::post('broadcast/stop', [LiveBroadcastController::class, 'stop'])->name('broadcast.stop');
                Route::get('broadcast/live-state', [LiveBroadcastController::class, 'liveState'])->name('broadcast.live-state');
                Route::post('broadcast/speakers/{speaker}/stage-link', [LiveBroadcastController::class, 'createStageLink'])
                    ->name('broadcast.stage-link.store');
                Route::delete('broadcast/speakers/{speaker}/stage-link', [LiveBroadcastController::class, 'revokeStageLink'])
                    ->name('broadcast.stage-link.destroy');

                // What attendees see on /live, for this event, opened in a new tab.
                Route::get('preview', [LiveControlController::class, 'preview'])->name('preview');

                Route::get('questions', [AdminQuestionController::class, 'index'])->name('questions.index');
                Route::post('questions/{question}/approve', [AdminQuestionController::class, 'approve'])->name('questions.approve');
                Route::post('questions/{question}/hide', [AdminQuestionController::class, 'hide'])->name('questions.hide');
                Route::post('questions/{question}/feature', [AdminQuestionController::class, 'feature'])->name('questions.feature');
                Route::post('questions/{question}/mark-answered', [AdminQuestionController::class, 'markAnswered'])->name('questions.mark-answered');
                Route::post('chat/{chatMessage}/hide', [AdminQuestionController::class, 'hideChat'])->name('chat.hide');

                Route::get('polls', [AdminPollController::class, 'index'])->name('polls.index');
                Route::get('polls/create', [AdminPollController::class, 'create'])->name('polls.create');
                Route::post('polls', [AdminPollController::class, 'store'])->name('polls.store');
                Route::post('polls/{poll}/open', [AdminPollController::class, 'open'])->name('polls.open');
                Route::post('polls/{poll}/close', [AdminPollController::class, 'close'])->name('polls.close');
            });

            // Feedback + engagement
            Route::get('feedback', [AdminFeedbackController::class, 'index'])->name('feedback.index');
            Route::get('engagement', [EngagementController::class, 'show'])->name('engagement.show');
        });

        // Pre-workspace URLs: send old bookmarks to the same page of the
        // default event (or the event list when there is none).
        foreach ([
            'programme-sessions' => 'admin.programme-sessions.index',
            'payments' => 'admin.payments.index',
            'check-in' => 'admin.check-in.index',
            'announcements' => 'admin.announcements.index',
            'resources' => 'admin.resources.index',
            'live/control-room' => 'admin.live.control-room',
            'live/questions' => 'admin.live.questions.index',
            'live/polls' => 'admin.live.polls.index',
            'feedback' => 'admin.feedback.index',
        ] as $uri => $target) {
            Route::get($uri, LegacyEventRedirectController::class)
                ->defaults('target', $target)
                ->name('legacy.'.str_replace('/', '-', $uri));
        }
    });
