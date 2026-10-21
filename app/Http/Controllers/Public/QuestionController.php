<?php

namespace App\Http\Controllers\Public;

use App\Enums\QuestionStatus;
use App\Events\QuestionSubmittedForModeration;
use App\Events\QuestionUpdated;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Question;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuestionController extends Controller
{
    public function store(Request $request, Event $event): RedirectResponse
    {
        $request->validate(['body' => ['required', 'string', 'max:500']]);

        $body = $request->input('body');
        $hash = $this->normalizedHash($body);

        // C6 — same user, same (normalised) text, within 5 minutes: a
        // friendly refusal instead of a duplicate row (double-tap on a
        // slow connection, or an accidental resubmit).
        $isDuplicate = Question::where('event_id', $event->id)
            ->where('user_id', $request->user()->id)
            ->where('normalized_hash', $hash)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($isDuplicate) {
            return back()->withErrors(['body' => "You've already submitted this question — it's awaiting moderation."])->withInput();
        }

        $question = Question::create([
            'event_id' => $event->id,
            'user_id' => $request->user()->id,
            'programme_session_id' => $event->current_session_id,
            'body' => $body,
            'status' => QuestionStatus::Submitted,
            'normalized_hash' => $hash,
        ]);

        broadcast(new QuestionSubmittedForModeration($question));

        return back()->with('success', 'Question submitted for moderation.');
    }

    public function upvote(Question $question): RedirectResponse
    {
        abort_unless($question->status->value === 'approved' || $question->status->value === 'answered', 403);

        $question->votes()->firstOrCreate(['user_id' => Auth::id()]);

        broadcast(new QuestionUpdated($question, 'vote'));

        return back();
    }

    private function normalizedHash(string $body): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $body)));

        return hash('sha256', $normalized);
    }
}
