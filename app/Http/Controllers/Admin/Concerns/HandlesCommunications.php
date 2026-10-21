<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Enums\CommunicationCategory;
use App\Http\Requests\Admin\SendCommunicationRequest;
use App\Http\Requests\Admin\SendTestCommunicationRequest;
use App\Http\Responses\SafeFileResponse;
use App\Models\Communication;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationRecipient;
use App\Models\Event;
use App\Notifications\CommunicationNotification;
use App\Services\CommunicationAudience;
use App\Services\CommunicationSender;
use App\Services\RegistrationForm;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The communications tool, shared by the per-event workspace
 * (CommunicationController) and the platform-wide super-admin area
 * (PlatformCommunicationController). $event null means platform-wide.
 * Callers authorise before calling in.
 */
trait HandlesCommunications
{
    private const PER_PAGE = 20;

    protected function communicationRoute(string $action, ?Event $event, array $params = []): string
    {
        return $event
            ? route("admin.communications.{$action}", ['event' => $event->id, ...$params])
            : route("admin.platform-communications.{$action}", $params);
    }

    protected function renderIndex(?Event $event): Response
    {
        $communications = Communication::query()
            ->when($event, fn ($q) => $q->where('event_id', $event->id), fn ($q) => $q->whereNull('event_id'))
            ->with('sender:id,name')
            ->latest()
            ->paginate(self::PER_PAGE);

        return Inertia::render('Admin/Communications/Index', [
            'isPlatform' => $event === null,
            'createUrl' => $this->communicationRoute('create', $event),
            'communications' => $communications->through(fn (Communication $c) => [
                'id' => $c->id,
                'subject' => $c->subject,
                'category' => $c->category->value,
                'recipient_mode' => $c->recipient_mode,
                'status' => $c->status,
                'recipient_count' => $c->recipient_count,
                'sent_count' => $c->sent_count,
                'failed_count' => $c->failed_count,
                'skipped_count' => $c->skipped_count,
                'sender' => $c->sender?->name,
                'created_at' => $c->created_at->toIso8601String(),
                'url' => $this->communicationRoute('show', $event, ['communication' => $c->id]),
            ]),
        ]);
    }

    protected function renderCompose(Request $request, ?Event $event, CommunicationAudience $audience): Response
    {
        $mode = in_array($request->query('mode'), CommunicationAudience::MODES, true) ? $request->query('mode') : 'all';
        $category = $event && $request->query('category') !== 'marketing'
            ? CommunicationCategory::Event
            : CommunicationCategory::Marketing;
        $filters = $audience->sanitizeFilters($event, (array) $request->query('filters', []));
        $userIds = array_filter(array_map('intval', (array) $request->query('user_ids', [])));

        return Inertia::render('Admin/Communications/Compose', [
            'isPlatform' => $event === null,
            'state' => [
                'mode' => $mode,
                'category' => $category->value,
                'filters' => (object) $filters,
            ],
            // Re-read on every filter change (a partial reload of just
            // these), so the count on the Send button is always current.
            'audience' => fn () => $this->audienceSummary($event, $category, $mode, $filters, $audience),
            'candidates' => fn () => $mode === 'selected'
                ? $audience->candidates($event, $category, $filters)->paginate(self::PER_PAGE)->withQueryString()
                : null,
            // A deep link from the attendee list ("Email" on a row).
            'preselected' => $userIds
                ? $audience->query($event, $category, 'selected', [], $userIds)
                    ->get(['users.id', 'users.name', 'users.email', 'users.organisation'])
                : [],
            'options' => [
                'industries' => config('seminar.industries'),
                'organisation_sizes' => config('seminar.organisation_sizes'),
                // The event's own interests and choice questions; platform-wide,
                // every interest any event offers (custom questions are per event).
                'interests' => $event
                    ? app(RegistrationForm::class)->filterOptions($event)['interests']
                    : RegistrationForm::allInterestLabels(),
                'questions' => $event ? app(RegistrationForm::class)->filterOptions($event)['questions'] : [],
                'events' => $event ? [] : Event::orderByDesc('starts_at')->get(['id', 'name'])->all(),
            ],
            'limits' => [
                'max_attachments' => SendCommunicationRequest::MAX_ATTACHMENTS,
                'max_total_kb' => SendCommunicationRequest::MAX_TOTAL_KB,
                'per_minute' => max(1, (int) config('seminar.communications.per_minute', 60)),
            ],
            'defaultTestEmail' => $request->user()->email,
            'urls' => [
                'compose' => $this->communicationRoute('create', $event),
                'store' => $this->communicationRoute('store', $event),
                'test' => $this->communicationRoute('test', $event),
                'index' => $this->communicationRoute('index', $event),
            ],
        ]);
    }

    private function audienceSummary(?Event $event, CommunicationCategory $category, string $mode, array $filters, CommunicationAudience $audience): array
    {
        // `selected` is counted in the browser (it's the ticked list).
        $countMode = $mode === 'selected' ? 'all' : $mode;
        $count = $audience->query($event, $category, $countMode, $filters)->count();

        // How many a marketing send leaves out for lack of consent, so the
        // admin isn't surprised by a smaller number than the attendee list.
        $withoutConsent = $category === CommunicationCategory::Marketing
            ? $audience->query($event, CommunicationCategory::Event, $countMode, $filters)->count() - $count
            : 0;

        return ['count' => $count, 'without_consent' => max(0, $withoutConsent)];
    }

    protected function storeCommunication(SendCommunicationRequest $request, ?Event $event, CommunicationAudience $audience, CommunicationSender $sender): RedirectResponse
    {
        $data = $request->validated();
        // A platform-wide email reaches people about something they did not
        // register for: that is direct marketing, so it is always consent-gated.
        $category = $event ? CommunicationCategory::from($data['category']) : CommunicationCategory::Marketing;
        $mode = $data['recipient_mode'];
        $filters = $audience->sanitizeFilters($event, $data['filters'] ?? []);
        $userIds = array_values(array_unique(array_map('intval', $data['user_ids'] ?? [])));

        $recipients = $audience->query($event, $category, $mode, $filters, $userIds);

        if (! (clone $recipients)->exists()) {
            return back()->withErrors(['recipient_mode' => $category === CommunicationCategory::Marketing
                ? 'No one matches who has agreed to hear from Lumko. Change the recipients or send it as an event update.'
                : 'No one matches these recipients.']);
        }

        $communication = Communication::create([
            'event_id' => $event?->id,
            'created_by' => $request->user()->id,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'category' => $category,
            'recipient_mode' => $mode,
            'filters' => match ($mode) {
                'filtered' => $filters,
                'selected' => ['user_ids' => $userIds],
                default => null,
            },
        ]);

        foreach ($request->file('attachments', []) as $file) {
            $communication->attachments()->create([
                'path' => $file->store("communications/{$communication->id}", 'local'),
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }

        $communication->load('attachments');
        $count = $sender->send($communication, $recipients);
        $minutes = (int) ceil($count / max(1, (int) config('seminar.communications.per_minute', 60)));

        return redirect($this->communicationRoute('show', $event, ['communication' => $communication->id]))
            ->with('success', "Queued for {$count} ".($count === 1 ? 'person' : 'people').'.'
                .($minutes > 1 ? " They go out over about {$minutes} minutes." : ''));
    }

    protected function sendTest(SendTestCommunicationRequest $request, ?Event $event): RedirectResponse
    {
        $data = $request->validated();
        $category = $event ? CommunicationCategory::from($data['category']) : CommunicationCategory::Marketing;

        $files = collect($request->file('attachments', []))->map(fn ($file) => [
            'path' => $file->getRealPath(),
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
        ])->all();

        try {
            Notification::route('mail', $data['test_email'])->notifyNow(new CommunicationNotification(
                '[TEST] '.$data['subject'], $data['body'], $category, $files, $request->user()->first_name,
            ));
        } catch (Throwable $e) {
            Log::warning('communication.test_failed', ['to' => $data['test_email'], 'error' => $e->getMessage()]);

            return back()->with('error', 'The test email could not be sent. Check the mail settings and try again.');
        }

        Audit::record('communication.test_sent', eventId: $event?->id, after: [
            'to' => $data['test_email'],
            'subject' => $data['subject'],
        ]);

        return back()->with('success', "Test email sent to {$data['test_email']}.");
    }

    protected function renderShow(?Event $event, Communication $communication): Response
    {
        $communication->load(['attachments', 'sender:id,name']);

        // Problems first: failed, then skipped, then still queued.
        $recipients = $communication->recipients()
            ->orderByRaw("case status when 'failed' then 0 when 'skipped_no_consent' then 1 when 'queued' then 2 else 3 end")
            ->orderBy('id')
            ->with('user:id,name')
            ->paginate(50);

        Audit::record('communication.recipients_viewed', subject: $communication, eventId: $event?->id);

        return Inertia::render('Admin/Communications/Show', [
            'isPlatform' => $event === null,
            'indexUrl' => $this->communicationRoute('index', $event),
            'communication' => [
                'id' => $communication->id,
                'subject' => $communication->subject,
                'body' => $communication->body,
                'category' => $communication->category->value,
                'recipient_mode' => $communication->recipient_mode,
                'filters' => $communication->filters,
                'status' => $communication->status,
                'recipient_count' => $communication->recipient_count,
                'sent_count' => $communication->sent_count,
                'failed_count' => $communication->failed_count,
                'skipped_count' => $communication->skipped_count,
                'sender' => $communication->sender?->name,
                'created_at' => $communication->created_at->toIso8601String(),
                'attachments' => $communication->attachments->map(fn (CommunicationAttachment $a) => [
                    'id' => $a->id,
                    'name' => $a->original_name,
                    'size' => $a->size,
                    'url' => $this->communicationRoute('attachment', $event, ['communication' => $communication->id, 'attachment' => $a->id]),
                ]),
            ],
            'recipients' => $recipients->through(fn (CommunicationRecipient $r) => [
                'id' => $r->id,
                'name' => $r->user?->name,
                'email' => $r->email,
                'status' => $r->status,
                'error' => $r->error,
                'sent_at' => $r->sent_at?->toIso8601String(),
            ]),
        ]);
    }

    protected function downloadAttachment(Communication $communication, CommunicationAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->communication_id === $communication->id, 404);

        return SafeFileResponse::make('local', $attachment->path, $attachment->original_name);
    }
}
