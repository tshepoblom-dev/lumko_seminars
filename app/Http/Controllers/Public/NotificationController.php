<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/** D-Notifications — the AttendeeLayout bell's mark-read actions. */
class NotificationController extends Controller
{
    public function markRead(string $id): RedirectResponse
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        Auth::user()->unreadNotifications->markAsRead();

        return back();
    }
}
