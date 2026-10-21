<?php

namespace App\Http\Controllers\Public;

use App\Events\ChatMessagePosted;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Live chat beside the stream. Unlike Q&A there's no approval step: a post
 * goes to everyone at once and staff hide it afterwards if they must
 * (Admin\QuestionController::hideChat). The `attendee` middleware has
 * already checked this user is registered for the event.
 */
class ChatController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:280']]);

        abort_if($event->ends_at?->isPast(), 403, 'This session has ended.');

        $message = ChatMessage::create([
            'event_id' => $event->id,
            'programme_session_id' => $event->current_session_id,
            'user_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);

        broadcast(new ChatMessagePosted($message));

        return back();
    }
}
