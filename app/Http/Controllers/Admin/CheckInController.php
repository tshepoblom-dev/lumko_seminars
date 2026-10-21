<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\CheckInService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * B5 — door check-in for one event: the event whose workspace the
 * operator is in. A ticket for any other event is refused by
 * CheckInService.
 */
class CheckInController extends Controller
{
    public function __construct(private CheckInService $checkIns) {}

    public function index(Event $event): Response
    {
        $this->authorize('update', $event);

        return Inertia::render('Admin/CheckIn/Index', [
            'event' => ['id' => $event->id, 'name' => $event->name],
        ]);
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $request->validate(['code' => ['required', 'string']]);

        $result = $this->checkIns->checkIn(
            $request->input('code'),
            $event,
            $request->user(),
            $request->ip(),
            $request->userAgent(),
        );

        return match ($result['result']) {
            CheckInService::RESULT_SUCCESS => back()->with('success', $result['message']),
            CheckInService::RESULT_ALREADY_CHECKED_IN => back()->with('info', $result['message']),
            default => back()->withErrors(['code' => $result['message']]),
        };
    }
}
