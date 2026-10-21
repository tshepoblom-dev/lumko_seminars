<?php

namespace App\Http\Controllers\Admin;

use App\Events\ChatMessageHidden;
use App\Events\QuestionUpdated;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Event;
use App\Models\Question;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuestionController extends Controller
{
    public function index(Event $event): Response
    {
        $this->authorize('viewAny', Question::class);
        $this->authorize('view', $event);

        return Inertia::render('Admin/Live/Questions', [
            'questions' => self::moderationList($event),
            'chat' => ChatMessage::recentFor($event, 100),
        ]);
    }

    /** Every question for the event, newest first — the moderation queue. */
    public static function moderationList(Event $event): array
    {
        return $event->questions()->with('user')->withCount('votes')
            ->latest()->latest('id')->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'body' => $q->body,
                'user_name' => $q->user->name,
                'status' => $q->status->value,
                'is_featured' => $q->is_featured,
                'votes_count' => $q->votes_count,
            ])
            ->all();
    }

    public function approve(Request $request, Event $event, Question $question): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $question);

        $question->update(['status' => 'approved']);
        broadcast(new QuestionUpdated($question, 'approved'));
        Audit::record('question.approved', subject: $question, eventId: $question->event_id);

        return $this->done($request, $event, 'Question approved.');
    }

    public function hide(Request $request, Event $event, Question $question): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $question);

        $question->update(['status' => 'hidden']);
        broadcast(new QuestionUpdated($question, 'hidden'));
        Audit::record('question.hidden', subject: $question, eventId: $question->event_id);

        return $this->done($request, $event, 'Question hidden.');
    }

    public function feature(Request $request, Event $event, Question $question): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $question);

        $question->update(['is_featured' => true]);
        broadcast(new QuestionUpdated($question, 'featured'));
        Audit::record('question.featured', subject: $question, eventId: $question->event_id);

        return $this->done($request, $event, 'Question featured.');
    }

    public function markAnswered(Request $request, Event $event, Question $question): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $question);

        $question->update(['status' => 'answered', 'answered_at' => now()]);
        broadcast(new QuestionUpdated($question, 'answered'));
        Audit::record('question.marked_answered', subject: $question, eventId: $question->event_id);

        return $this->done($request, $event, 'Question marked answered.');
    }

    /**
     * Staff hide a live-chat post after the fact (chat has no approval
     * step). Everyone's panel drops it via ChatMessageHidden.
     */
    public function hideChat(Request $request, Event $event, ChatMessage $chatMessage): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $event);
        $message = $chatMessage; // scoped to the event by the route

        if (! $message->hidden_at) {
            $message->update(['hidden_at' => now(), 'hidden_by' => $request->user()->id]);
            broadcast(new ChatMessageHidden($message));
            Audit::record('chat.hidden', subject: $message, eventId: $event->id, before: ['body' => $message->body]);
        }

        return $request->wantsJson()
            ? response()->json(['ok' => true])
            : back()->with('success', 'Chat message hidden.');
    }

    /** The Broadcast console moderates without leaving the page (JSON). */
    private function done(Request $request, Event $event, string $message): RedirectResponse|JsonResponse
    {
        return $request->wantsJson()
            ? response()->json(['questions' => self::moderationList($event)])
            : back()->with('success', $message);
    }
}
