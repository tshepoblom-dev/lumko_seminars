<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin areas used to be top-level pages (/admin/payments, …); they now
 * live inside each event's workspace. Old bookmarks land on the same page
 * for the staff member's default event (the one the admin nav used to
 * point at), or on the event list when they can't see any event.
 */
class LegacyEventRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $eventId = Event::visibleTo($request->user())->notArchived()->orderByDesc('starts_at')->value('id');

        if (! $eventId) {
            return redirect()->route('admin.events.index');
        }

        return redirect()->route($request->route()->defaults['target'], ['event' => $eventId]);
    }
}
