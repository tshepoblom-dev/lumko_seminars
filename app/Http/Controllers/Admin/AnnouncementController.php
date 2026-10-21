<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Http\Requests\Admin\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\Event;
use App\Services\AnnouncementMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function __construct(private AnnouncementMailer $mailer) {}

    public function index(Event $event): Response
    {
        $this->authorize('viewAny', Announcement::class);
        $this->authorize('view', $event);

        return Inertia::render('Admin/Announcements/Index', [
            'announcements' => $event->announcements()->latest()->get(),
        ]);
    }

    public function create(Event $event): Response
    {
        $this->authorize('create', Announcement::class);
        $this->authorize('update', $event);

        return Inertia::render('Admin/Announcements/Create');
    }

    public function store(StoreAnnouncementRequest $request, Event $event): RedirectResponse
    {
        Announcement::create($request->validated() + ['created_by' => $request->user()->id]);

        return redirect()->route('admin.announcements.index', $event)->with('success', 'Announcement saved.');
    }

    public function edit(Event $event, Announcement $announcement): Response
    {
        $this->authorize('update', $announcement);

        return Inertia::render('Admin/Announcements/Edit', [
            'announcement' => $announcement,
        ]);
    }

    public function update(UpdateAnnouncementRequest $request, Event $event, Announcement $announcement): RedirectResponse
    {
        $announcement->update($request->validated());

        return redirect()->route('admin.announcements.index', $event)->with('success', 'Announcement updated.');
    }

    public function destroy(Event $event, Announcement $announcement): RedirectResponse
    {
        $this->authorize('delete', $announcement);

        $announcement->delete();

        return redirect()->route('admin.announcements.index', $event)->with('success', 'Announcement deleted.');
    }

    public function sendEmail(Request $request, Event $event, Announcement $announcement): RedirectResponse
    {
        $this->authorize('update', $announcement);

        // B14 — email only ever goes out for a published announcement; a
        // draft's content isn't meant for attendees yet.
        if (! $announcement->published_at || $announcement->published_at->isFuture()) {
            return back()->with('error', 'This announcement must be published before it can be emailed.');
        }

        $resend = $request->boolean('resend');

        if ($announcement->emailed_at && ! $resend) {
            return back()->with(
                'error',
                'This announcement was already emailed on '.$announcement->emailed_at->format('d M Y H:i').
                '. Confirm again to resend.'
            );
        }

        $count = $this->mailer->send($announcement, $resend);

        if ($count === null) {
            return back()->with('error', 'This announcement is already being sent. Try again shortly.');
        }

        return back()->with('success', "Queued for {$count} registered attendee(s).");
    }
}
