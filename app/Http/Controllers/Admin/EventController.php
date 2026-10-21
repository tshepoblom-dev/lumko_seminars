<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEventRequest;
use App\Http\Requests\Admin\UpdateEventRequest;
use App\Http\Requests\Admin\UpdateLivestreamSettingsRequest;
use App\Models\Event;
use App\Services\EventStatsService;
use App\Services\RegistrationForm;
use App\Support\Audit;
use App\Support\EventDeletionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(EventDeletionGuard $guard): Response
    {
        $this->authorize('viewAny', Event::class);

        return Inertia::render('Admin/Events/Index', [
            // B11 — an event manager sees only events they're assigned to;
            // a super admin sees all.
            'events' => Event::visibleTo(Auth::user())->orderByDesc('starts_at')->get()->map(function (Event $event) use ($guard) {
                $blocking = $guard->blockingCounts($event);

                return [
                    ...$event->toArray(),
                    'can_delete' => $blocking === [],
                    'blocking_summary' => $blocking !== [] ? collect($blocking)
                        ->map(fn ($count, $relation) => "{$count} ".str_replace('_', ' ', $relation))
                        ->implode(', ') : null,
                ];
            }),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Event::class);

        return Inertia::render('Admin/Events/Create');
    }

    public function store(StoreEventRequest $request): RedirectResponse
    {
        $event = Event::create($this->attributesWithImages($request));

        // An event manager who creates an event can work on it straight
        // away (B11 scoping would otherwise hide it from them).
        if (! $request->user()->isSuperAdmin()) {
            $event->managers()->syncWithoutDetaching([$request->user()->id => ['role' => 'event_manager']]);
        }

        // The standard registration questions, ready to edit (Event → Registration form).
        app(RegistrationForm::class)->ensureDefaults($event);

        return redirect()->route('admin.events.show', $event)->with('success', 'Event created.');
    }

    /**
     * The event workspace's Overview: headline numbers and the things
     * that currently need someone's attention.
     */
    public function show(Event $event, EventStatsService $stats): Response
    {
        $this->authorize('view', $event);

        $summary = $stats->summary($event);
        $payments = $stats->dashboardExtras($event)['payment_totals'];

        return Inertia::render('Admin/Events/Show', [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'subtitle' => $event->subtitle,
                'status' => $event->status,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'venue_name' => $event->venue_name,
                'is_live' => (bool) $event->is_live,
            ],
            'stats' => [
                'registered_total' => $summary['registered_total'],
                'registered_online' => $summary['registered_online'],
                'registered_in_person' => $summary['registered_in_person'],
                'checked_in' => $summary['checked_in'],
                'payments_approved' => $payments['approved'],
                'payments_complimentary' => $payments['complimentary'],
                'verified_amount' => $payments['verified_amount'],
                'sessions' => $event->programmeSessions()->count(),
                'speakers' => $event->speakers()->count(),
                'feedback_count' => $summary['feedback_count'],
                'feedback_average_rating' => $summary['feedback_average_rating'],
            ],
            'attention' => [
                'payments_to_review' => $payments['under_review'],
                'accounts_not_set_up' => $event->registrations()
                    ->whereHas('user', fn ($q) => $q->whereNull('email_verified_at')->where('email', 'not like', '%@invalid'))
                    ->count(),
                'sessions_without_speakers' => $event->programmeSessions()->doesntHave('speakers')->count(),
                'questions_pending' => $event->questions()->where('status', 'submitted')->count(),
            ],
        ]);
    }

    public function edit(Event $event): Response
    {
        $this->authorize('update', $event);

        return Inertia::render('Admin/Events/Edit', ['event' => $event]);
    }

    public function update(UpdateEventRequest $request, Event $event): RedirectResponse
    {
        $before = $event->only(['hero_image_path', 'venue_image_path']);

        $event->update($this->attributesWithImages($request, $event));

        // A replaced or removed image leaves no orphan file behind.
        foreach ($before as $column => $previousPath) {
            if ($previousPath && $previousPath !== $event->{$column}) {
                Storage::disk('public')->delete($previousPath);
                Audit::record('event.image_replaced', subject: $event, eventId: $event->id, before: [$column => $previousPath], after: [$column => $event->{$column}]);
            }
        }

        return redirect()->route('admin.events.show', $event)->with('success', 'Event updated.');
    }

    public function destroy(Event $event, EventDeletionGuard $guard): RedirectResponse
    {
        $this->authorize('delete', $event);

        // D5/B12 — refuse with a friendly, specific message whenever any
        // related data exists; the database's own restrictOnDelete() FKs
        // are the last line of defence, not the first.
        $blocking = $guard->blockingCounts($event);

        if ($blocking !== []) {
            $summary = collect($blocking)
                ->map(fn ($count, $relation) => "{$count} ".str_replace('_', ' ', $relation))
                ->implode(', ');

            return back()->with('error', "This event can't be deleted — it still has: {$summary}. Archive it instead.");
        }

        app(RegistrationForm::class)->deleteFiles($event);
        $event->delete();
        Audit::record('event.deleted', actor: Auth::user());

        return redirect()->route('admin.events.index')->with('success', 'Event deleted.');
    }

    public function archive(Event $event): RedirectResponse
    {
        $this->authorize('delete', $event);

        $from = $event->status;
        $event->update(['status' => 'archived']);

        Audit::record('event.archived', subject: $event, actor: Auth::user(), before: ['status' => $from], after: ['status' => 'archived']);

        return back()->with('success', 'Event archived. It is now hidden from the public site.');
    }

    public function updateLivestream(UpdateLivestreamSettingsRequest $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $event->update($request->validated());
        Audit::record('event.livestream_updated', subject: $event, eventId: $event->id);

        return back()->with('success', 'Livestream settings updated.');
    }

    /**
     * The validated event fields with the two landing-page images resolved:
     * a new upload is stored on the public disk, a remove flag clears the
     * slot, and alt text can be edited on its own for an existing image.
     */
    private function attributesWithImages(Request $request, ?Event $event = null): array
    {
        $data = collect($request->validated())
            ->except(['hero_image', 'hero_image_alt', 'remove_hero_image', 'venue_image', 'venue_image_alt', 'remove_venue_image'])
            ->all();

        foreach (['hero', 'venue'] as $slot) {
            $path = "{$slot}_image_path";
            $alt = "{$slot}_image_alt";

            if ($request->hasFile("{$slot}_image")) {
                $data[$path] = $request->file("{$slot}_image")->store('events', 'public');
                $data[$alt] = $request->validated($alt);
            } elseif ($event && $request->boolean("remove_{$slot}_image")) {
                $data[$path] = null;
                $data[$alt] = null;
            } elseif ($event?->{$path} && $request->filled($alt)) {
                $data[$alt] = $request->validated($alt);
            }
        }

        return $data;
    }
}
