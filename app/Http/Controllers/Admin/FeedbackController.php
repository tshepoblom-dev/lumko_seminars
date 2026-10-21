<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Feedback;
use App\Support\Audit;
use Inertia\Inertia;
use Inertia\Response;

class FeedbackController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('viewAny', Feedback::class);
        $this->authorize('view', $event);

        $feedback = $event->feedback()->with(['user', 'event'])
            ->latest()
            ->get();

        // D-Admin — contact details (name/email/mobile) are only ever
        // shown, and only ever audited as viewed, on rows where the
        // attendee explicitly asked to be followed up with. Every other
        // row is reviewed anonymously: rating/takeaway/topics only.
        $withContactViewed = $feedback->where('wants_contact', true);
        foreach ($withContactViewed as $f) {
            Audit::record('feedback.contact_viewed', subject: $f, eventId: $f->event_id);
        }

        return Inertia::render('Admin/Feedback/Index', [
            'summary' => [
                'count' => $feedback->count(),
                'average_rating' => $feedback->count() ? round($feedback->avg('rating'), 1) : null,
                'wants_contact_count' => $withContactViewed->count(),
            ],
            'feedback' => $feedback->map(fn (Feedback $f) => [
                'id' => $f->id,
                'event' => $f->event->name,
                'rating' => $f->rating,
                'biggest_takeaway' => $f->biggest_takeaway,
                'topics_next' => $f->topics_next ?? [],
                'wants_contact' => $f->wants_contact,
                'contact_topics' => $f->contact_topics ?? [],
                'contact' => $f->wants_contact ? [
                    'name' => $f->user->name,
                    'email' => $f->user->email,
                    'mobile' => $f->user->mobile,
                    'organisation' => $f->user->organisation,
                ] : null,
            ]),
        ]);
    }
}
