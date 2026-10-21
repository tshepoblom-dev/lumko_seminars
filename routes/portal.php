<?php

use App\Http\Controllers\Partner\InsightsController;
use App\Http\Controllers\Partner\LeadController;
use App\Http\Controllers\Partner\PartnerPortalController;
use App\Http\Controllers\Partner\PartnerResourceController;
use App\Http\Controllers\Partner\ScanController;
use App\Http\Controllers\Partner\TeamController;
use App\Http\Controllers\Partner\TicketController;
use App\Http\Controllers\Portal\PortalInvitationController;
use App\Http\Controllers\Speaker\SpeakerPortalController;
use App\Http\Controllers\Speaker\SpeakerResourceController;
use App\Http\Controllers\Speaker\SpeakerStageController;
use Illuminate\Support\Facades\Route;

// Portal invitations (speakers, partner team members). No login needed to
// open one: the emailed, single-use link is the credential, and
// PortalInvitationController decides whether to create an account or ask
// the person to log in first.
Route::middleware('throttle:portal-invitation')->group(function () {
    Route::get('/invitation/{token}', [PortalInvitationController::class, 'show'])->name('portal-invitation.show');
    Route::post('/invitation/{token}', [PortalInvitationController::class, 'store'])->name('portal-invitation.store');
});

// The speaker portal: an ordinary account linked to a speaker profile.
Route::middleware(['auth', 'verified', 'legal', 'speaker'])
    ->prefix('speaker')
    ->name('speaker.')
    ->group(function () {
        Route::get('/', [SpeakerPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/profile', [SpeakerPortalController::class, 'editProfile'])->name('profile.edit');
        // POST, not PUT: the form can carry a photo (multipart).
        Route::post('/profile', [SpeakerPortalController::class, 'updateProfile'])
            ->middleware('throttle:portal-upload')->name('profile.update');

        Route::prefix('events/{event}')->name('events.')->group(function () {
            Route::get('/report', [SpeakerPortalController::class, 'report'])->name('report');
            Route::get('/report/contacts.csv', [SpeakerPortalController::class, 'reportContacts'])
                ->middleware('throttle:portal-export')->name('report.contacts');

            Route::get('/resources', [SpeakerResourceController::class, 'index'])->name('resources.index');
            Route::post('/resources', [SpeakerResourceController::class, 'store'])
                ->middleware('throttle:portal-upload')->name('resources.store');
            Route::delete('/resources/{resource}', [SpeakerResourceController::class, 'destroy'])->name('resources.destroy');

            Route::get('/green-room', [SpeakerStageController::class, 'greenRoom'])->name('green-room');
            Route::post('/green-room/credentials', [SpeakerStageController::class, 'greenRoomCredentials'])
                ->middleware('throttle:greenroom-credentials')->name('green-room.credentials');

            Route::get('/stage', [SpeakerStageController::class, 'show'])->name('stage');
            Route::post('/stage/credentials', [SpeakerStageController::class, 'credentials'])
                ->middleware('throttle:greenroom-credentials')->name('stage.credentials');
            Route::get('/stage/live', [SpeakerStageController::class, 'liveState'])->name('stage.live');
            Route::post('/stage/polls', [SpeakerStageController::class, 'storePoll'])->name('stage.polls.store');
            Route::post('/stage/polls/{poll}/open', [SpeakerStageController::class, 'openPoll'])->name('stage.polls.open');
            Route::post('/stage/polls/{poll}/close', [SpeakerStageController::class, 'closePoll'])->name('stage.polls.close');
            Route::post('/stage/broadcasting/auth', [SpeakerStageController::class, 'broadcastingAuth'])
                ->middleware('throttle:broadcasting-auth')->name('stage.broadcasting-auth');
        });
    });

// A partner's portal: for the partner's team (partner_members) and super
// admins (PartnerPolicy::accessPortal). Per-event pages check the feature
// the partner's tier unlocks.
Route::middleware(['auth', 'verified', 'legal', 'can:accessPortal,partner'])
    ->prefix('partner/{partner}')
    ->name('partner.')
    ->group(function () {
        Route::get('/', [PartnerPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/profile', [PartnerPortalController::class, 'editProfile'])->name('profile.edit');
        Route::post('/profile', [PartnerPortalController::class, 'updateProfile'])
            ->middleware('throttle:portal-upload')->name('profile.update');

        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team', [TeamController::class, 'invite'])
            ->middleware('throttle:portal-invite-send')->name('team.invite');
        Route::delete('/team/{user}', [TeamController::class, 'remove'])->name('team.remove');
        Route::delete('/team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');

        Route::prefix('events/{event}')->name('events.')->group(function () {
            Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
            Route::get('/leads/export.csv', [LeadController::class, 'export'])
                ->middleware('throttle:portal-export')->name('leads.export');
            Route::post('/leads/{partnerConnection}/notes', [LeadController::class, 'storeNote'])->name('leads.notes.store');

            Route::get('/scan', [ScanController::class, 'show'])->name('scan.show');
            Route::post('/scan', [ScanController::class, 'lookup'])
                ->middleware('throttle:partner-scan')->name('scan.lookup');
            Route::post('/scan/{scanId}/confirm', [ScanController::class, 'confirm'])->name('scan.confirm');
            Route::delete('/scan/{scanId}', [ScanController::class, 'decline'])->name('scan.decline');

            Route::get('/referrals', [InsightsController::class, 'referrals'])->name('referrals');
            Route::get('/analytics', [InsightsController::class, 'analytics'])->name('analytics');

            Route::get('/resources', [PartnerResourceController::class, 'index'])->name('resources.index');
            Route::post('/resources', [PartnerResourceController::class, 'store'])
                ->middleware('throttle:portal-upload')->name('resources.store');
            Route::delete('/resources/{resource}', [PartnerResourceController::class, 'destroy'])->name('resources.destroy');

            Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
            Route::post('/tickets', [TicketController::class, 'store'])
                ->middleware('throttle:voucher-send')->name('tickets.store');
        });
    });
