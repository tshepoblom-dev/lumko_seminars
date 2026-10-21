<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Speaker;
use App\Notifications\SpeakerReportLinkNotification;
use App\Services\SpeakerReport;
use App\Support\Audit;
use App\Support\SpeakerReportLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Event → Speakers: email a speaker the private link to their report
 * (the people who chose to connect with them + their session report),
 * revoke it, or preview what they'll see.
 */
class SpeakerReportController extends Controller
{
    public function send(Event $event, Speaker $speaker, SpeakerReport $report): RedirectResponse
    {
        $this->authorize('update', $event);

        if (blank($speaker->contact_email)) {
            return back()->with('error', "Add a contact email to {$speaker->name}'s profile first.");
        }

        // A new link replaces any earlier one, which stops working now.
        $link = SpeakerReportLink::issue($event, $speaker);
        $contacts = $report->contacts($event, $speaker)->count();

        try {
            Notification::route('mail', $speaker->contact_email)->notifyNow(new SpeakerReportLinkNotification(
                $speaker->name, $event->name, $link['url'], $link['expires_at'], $contacts,
            ));
        } catch (Throwable $e) {
            SpeakerReportLink::revoke($event, $speaker);
            Log::warning('speaker.report_link_failed', ['speaker_id' => $speaker->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'The email could not be sent, so no link was issued. Check the mail settings and try again.');
        }

        SpeakerReportLink::markSent($event, $speaker);

        Audit::record('speaker.report_link_sent', subject: $speaker, eventId: $event->id, after: [
            'to' => $speaker->contact_email,
            'expires_at' => $link['expires_at']->toIso8601String(),
            'contacts' => $contacts,
        ]);

        return back()->with('success', "Report link emailed to {$speaker->name} ({$speaker->contact_email}). It works until {$link['expires_at']->format('j M Y')}.");
    }

    public function revoke(Event $event, Speaker $speaker): RedirectResponse
    {
        $this->authorize('update', $event);

        SpeakerReportLink::revoke($event, $speaker);
        Audit::record('speaker.report_link_revoked', subject: $speaker, eventId: $event->id);

        return back()->with('success', "{$speaker->name}'s report link no longer works.");
    }

    /** What the speaker sees, for staff (contact details included, so audited). */
    public function preview(Event $event, Speaker $speaker, SpeakerReport $report): Response
    {
        $this->authorize('update', $event);

        $data = $report->build($event, $speaker);

        Audit::record('speaker.report_previewed', subject: $speaker, eventId: $event->id, after: [
            'contacts_shown' => count($data['contacts']),
        ]);

        return Inertia::render('Public/SpeakerReport', [...$data, 'csvUrl' => null, 'preview' => true]);
    }
}
