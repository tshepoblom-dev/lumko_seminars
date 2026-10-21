<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\EventResolver;
use App\Support\PartnerListing;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __construct(private EventResolver $events) {}

    public function index(): Response
    {
        // D-Public home — EventResolver::currentPublicEvent() (unlike the
        // old upcomingOrCurrent() scope) keeps returning the event for a
        // while after it ends, so the home page can show a real post-event
        // state instead of dropping straight to "no event published".
        // The programme is not sent here: it is for registered attendees and
        // lives on their dashboard (owner decision 2026-09-28).
        $event = $this->events->currentPublicEvent()?->load('speakers');

        $hasEnded = $event && $event->ends_at?->isPast();

        return Inertia::render('Public/Home', [
            // The series outlives any one event, so it is sent even when no
            // event is published and the page can always explain it.
            'series' => config('seminar.series'),
            'event' => $event ? [
                'name' => $event->name,
                'subtitle' => $event->subtitle,
                'description' => $event->description,
                'venue_name' => $event->venue_name,
                'venue_address' => $event->venue_address,
                // Landing-page photography, set per event in admin.
                'hero_image_url' => $event->hero_image_path ? asset('storage/'.$event->hero_image_path) : null,
                'hero_image_alt' => $event->hero_image_alt,
                'venue_image_url' => $event->venue_image_path ? asset('storage/'.$event->venue_image_path) : null,
                'venue_image_alt' => $event->venue_image_alt,
                'starts_at' => $event->starts_at->toIso8601String(),
                'ends_at' => $event->ends_at->toIso8601String(),
                'has_ended' => $hasEnded,
                'is_live' => $event->is_live && ! $hasEnded,
                'recording_url' => $hasEnded ? $event->recording_url : null,
                'online_price' => (float) $event->online_price,
                'in_person_price' => (float) $event->in_person_price,
                // Off: the page presents in-person attendance only.
                'offers_online' => $event->offersOnline(),
                'speakers' => $event->speakers->map(fn ($speaker) => [
                        'name' => $speaker->name,
                        'title' => $speaker->title,
                        'organisation' => $speaker->organisation,
                        'bio' => $speaker->bio,
                        'photo_url' => $speaker->photo_approved && $speaker->photo_path ? asset('storage/'.$speaker->photo_path) : null,
                        'photo_alt' => $speaker->photo_alt,
                    ]),
                // Partners whose tier includes a listing, grouped by tier.
                'partners' => PartnerListing::forEvent($event),
            ] : null,
        ]);
    }
}
