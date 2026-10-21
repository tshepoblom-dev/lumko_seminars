<?php

namespace App\Http\Controllers\Public;

use App\Enums\ConnectionStatus;
use App\Enums\ConsentType;
use App\Http\Controllers\Controller;
use App\Models\AttendeeConsent;
use App\Models\Connection;
use App\Models\EventRegistration;
use App\Notifications\ConnectionAccepted;
use App\Notifications\ConnectionRequested;
use App\Services\ConsentService;
use App\Support\Audit;
use App\Support\EventResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NetworkingController extends Controller
{
    public function __construct(
        private EventResolver $events,
        private ConsentService $consent,
    ) {}

    public function index(Request $request): Response
    {
        $registration = $this->events->registrationFor(Auth::user());

        if (! $registration) {
            return Inertia::render('Public/NetworkingUnavailable');
        }

        $event = $registration->event;
        $iOptedIn = $this->consent->isGranted(Auth::user(), ConsentType::Networking, $event);

        // B9 — reciprocity: the directory is visible only to attendees who
        // have themselves opted in. Not opted in yet gets the opt-in
        // prompt and an empty directory, not a peek at who else is in it.
        $directory = collect();

        // D-Networking — "My connections" and incoming requests are shown
        // regardless of current opt-in status: withdrawing consent hides
        // you from the *directory*, it doesn't erase connections you
        // already made or requests already sent to you.
        $allMyConnections = Connection::with(['requester', 'recipient'])
            ->where('event_id', $event->id)
            ->where(fn ($q) => $q->where('requester_id', Auth::id())->orWhere('recipient_id', Auth::id()))
            ->get();

        $connectionStatuses = $allMyConnections->mapWithKeys(function (Connection $c) {
            $otherId = $c->requester_id === Auth::id() ? $c->recipient_id : $c->requester_id;

            return [$otherId => [
                'id' => $c->id,
                'status' => $c->status->value,
                'i_requested' => $c->requester_id === Auth::id(),
            ]];
        });

        if ($iOptedIn) {
            $visibleUserIds = AttendeeConsent::where('event_id', $event->id)
                ->where('type', ConsentType::Networking->value)
                ->whereNotNull('granted_at')
                ->whereNull('revoked_at')
                ->pluck('user_id');

            $visibleRegistrations = EventRegistration::with('user')
                ->where('event_id', $event->id)
                ->whereIn('user_id', $visibleUserIds)
                ->where('user_id', '!=', Auth::id())
                ->get();

            $directory = $visibleRegistrations->map(fn (EventRegistration $r) => [
                'registration_id' => $r->public_id,
                'name' => $r->user->name,
                'organisation' => $r->user->organisation,
                'job_title' => $r->user->job_title,
                'attendance_mode' => $r->attendance_mode->value,
                'attendance_mode_label' => $r->attendance_mode->label(),
                'interests' => $r->interests ?? [],
                '_user_id' => $r->user_id,
            ]);
        }

        $incomingRequests = $allMyConnections
            ->filter(fn (Connection $c) => $c->status === ConnectionStatus::Pending && $c->recipient_id === Auth::id())
            ->map(fn (Connection $c) => [
                'id' => $c->id,
                'name' => $c->requester->name,
                'organisation' => $c->requester->organisation,
                'job_title' => $c->requester->job_title,
            ])->values();

        $myConnections = $allMyConnections
            ->filter(fn (Connection $c) => $c->status === ConnectionStatus::Accepted)
            ->map(function (Connection $c) {
                $other = $c->requester_id === Auth::id() ? $c->recipient : $c->requester;

                return [
                    'id' => $c->id,
                    'name' => $other->name,
                    'organisation' => $other->organisation,
                    'job_title' => $other->job_title,
                ];
            })->values();

        return Inertia::render('Public/Networking', [
            'iOptedIn' => $iOptedIn,
            'directory' => $directory->map(fn ($entry) => [
                'registration_id' => $entry['registration_id'],
                'name' => $entry['name'],
                'organisation' => $entry['organisation'],
                'job_title' => $entry['job_title'],
                'attendance_mode' => $entry['attendance_mode'],
                'attendance_mode_label' => $entry['attendance_mode_label'],
                'interests' => $entry['interests'],
                'connection' => $connectionStatuses->get($entry['_user_id']),
            ])->values(),
            'incomingRequests' => $incomingRequests,
            'myConnections' => $myConnections,
        ]);
    }

    public function request(EventRegistration $registration): RedirectResponse
    {
        $myRegistration = $this->events->registrationFor(Auth::user());
        abort_unless($myRegistration, 403);

        // B9 — target must be a registered, opted-in attendee of the same
        // event as the requester.
        abort_if($registration->event_id !== $myRegistration->event_id, 404);
        abort_if($registration->user_id === Auth::id(), 422, 'You cannot connect with yourself.');
        abort_unless($registration->status === 'registered', 404);

        $iOptedIn = $this->consent->isGranted(Auth::user(), ConsentType::Networking, $myRegistration->event);
        $theyOptedIn = $this->consent->isGranted($registration->user, ConsentType::Networking, $registration->event);
        abort_unless($iOptedIn && $theyOptedIn, 422, 'Networking is not available for this attendee.');

        $connection = DB::transaction(function () use ($myRegistration, $registration) {
            // Reverse-request: if they already requested us, surface it as
            // an incoming request instead of creating a second row.
            $reverse = Connection::where('event_id', $myRegistration->event_id)
                ->where('requester_id', $registration->user_id)
                ->where('recipient_id', Auth::id())
                ->first();

            if ($reverse) {
                return $reverse;
            }

            $connection = Connection::firstOrCreate([
                'event_id' => $myRegistration->event_id,
                'requester_id' => Auth::id(),
                'recipient_id' => $registration->user_id,
            ], ['status' => ConnectionStatus::Pending]);

            if ($connection->wasRecentlyCreated) {
                $registration->user->notify(new ConnectionRequested($connection));
            }

            return $connection;
        });

        return back()->with('success',
            $connection->wasRecentlyCreated
                ? 'Connection request sent.'
                : 'They already requested to connect with you — check your incoming requests.'
        );
    }

    public function accept(Connection $connection): RedirectResponse
    {
        abort_unless($connection->recipient_id === Auth::id(), 404);
        abort_unless($connection->status === ConnectionStatus::Pending, 422, 'This request is no longer pending.');

        $connection->update(['status' => ConnectionStatus::Accepted]);
        $connection->requester->notify(new ConnectionAccepted($connection));
        Audit::record('networking.connection_accepted', subject: $connection, eventId: $connection->event_id);

        return back()->with('success', 'Connection accepted.');
    }

    public function decline(Connection $connection): RedirectResponse
    {
        abort_unless($connection->recipient_id === Auth::id(), 404);
        abort_unless($connection->status === ConnectionStatus::Pending, 422, 'This request is no longer pending.');

        $connection->update(['status' => ConnectionStatus::Declined]);

        return back();
    }

    /** D-Networking — the requester can withdraw a request they sent. */
    public function withdraw(Connection $connection): RedirectResponse
    {
        abort_unless($connection->requester_id === Auth::id(), 404);
        abort_unless($connection->status === ConnectionStatus::Pending, 422, 'This request is no longer pending.');

        $connection->delete();

        return back()->with('success', 'Request withdrawn.');
    }

    /** D-Networking — either party can remove an accepted connection. */
    public function remove(Connection $connection): RedirectResponse
    {
        abort_unless(
            $connection->requester_id === Auth::id() || $connection->recipient_id === Auth::id(),
            404
        );
        abort_unless($connection->status === ConnectionStatus::Accepted, 422, 'This connection is not currently active.');

        $connection->delete();

        return back()->with('success', 'Connection removed.');
    }
}
