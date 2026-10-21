<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\RegistrationController;
use App\Models\Event;
use App\Models\RegistrationAnswer;
use App\Models\RegistrationQuestion;
use App\Services\RegistrationForm;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event → Registration form: the questions on the two editable steps of
 * this event's registration form (RegistrationForm): step 4 with the
 * standard questions, and step 5, which is left out of the form until it
 * has a question. Questions can change
 * after people have registered: answers keep the wording they were given,
 * an option is hidden rather than removed, and a question that has answers
 * can only be switched off, never deleted. The three built-in questions
 * keep their type and can't be deleted either.
 */
class RegistrationQuestionController extends Controller
{
    public function __construct(private RegistrationForm $form) {}

    public function index(Event $event): Response
    {
        $this->authorize('view', $event);

        $questions = $this->form->questions($event, activeOnly: false);
        $answerCounts = RegistrationAnswer::whereIn('registration_question_id', $questions->pluck('id'))
            ->selectRaw('registration_question_id, count(*) as total')
            ->groupBy('registration_question_id')
            ->pluck('total', 'registration_question_id');

        return Inertia::render('Admin/Events/RegistrationForm', [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'types' => self::typeOptions(),
            'pageTitles' => array_values($this->form->pageTitles($event)),
            'questions' => $questions->map(fn (RegistrationQuestion $q) => [
                'id' => $q->id,
                'key' => $q->key,
                'page' => $q->page,
                'type' => $q->type,
                'label' => $q->label,
                'help_text' => $q->help_text,
                'required' => $q->required,
                'is_active' => $q->is_active,
                'options' => $q->options ?? [],
                'settings' => $q->settings ?? (object) [],
                'answers_count' => (int) ($answerCounts[$q->id] ?? 0),
            ])->values(),
            'hasAnswers' => $answerCounts->sum() > 0,
            'copySources' => Event::visibleTo(Auth::user())->whereKeyNot($event->id)
                ->whereHas('registrationQuestions')
                ->orderByDesc('starts_at')->get(['id', 'name'])
                ->map(fn (Event $e) => ['id' => $e->id, 'name' => $e->name]),
        ]);
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $data = $this->validated($request, null);
        $question = $event->registrationQuestions()->create([
            ...$data,
            'sort_order' => (int) $event->registrationQuestions()->where('page', $data['page'])->max('sort_order') + 1,
        ]);

        Audit::record('registration_question.created', subject: $question, eventId: $event->id, after: $question->only(['label', 'type', 'page']));

        return back()->with('success', 'Question added.');
    }

    public function update(Request $request, Event $event, RegistrationQuestion $registrationQuestion): RedirectResponse
    {
        $this->authorize('update', $event);

        $before = $registrationQuestion->only(['label', 'type', 'required', 'options']);
        $registrationQuestion->update($this->validated($request, $registrationQuestion));

        Audit::record('registration_question.updated', subject: $registrationQuestion, eventId: $event->id,
            before: $before, after: $registrationQuestion->only(['label', 'type', 'required', 'options']));

        return back()->with('success', 'Question saved.');
    }

    public function move(Request $request, Event $event, RegistrationQuestion $registrationQuestion): RedirectResponse
    {
        $this->authorize('update', $event);

        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $siblings = $event->registrationQuestions()->where('page', $registrationQuestion->page)->get()->values();
        $index = $siblings->search(fn ($q) => $q->id === $registrationQuestion->id);
        $swap = $siblings->get($direction === 'up' ? $index - 1 : $index + 1);

        if ($swap) {
            DB::transaction(function () use ($siblings, $index, $direction) {
                $ordered = $siblings->all();
                $target = $direction === 'up' ? $index - 1 : $index + 1;
                [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
                foreach (array_values($ordered) as $i => $q) {
                    $q->forceFill(['sort_order' => $i])->save();
                }
            });
        }

        return back();
    }

    public function toggle(Event $event, RegistrationQuestion $registrationQuestion): RedirectResponse
    {
        $this->authorize('update', $event);

        $registrationQuestion->update(['is_active' => ! $registrationQuestion->is_active]);
        Audit::record('registration_question.'.($registrationQuestion->is_active ? 'shown' : 'hidden'), subject: $registrationQuestion, eventId: $event->id);

        return back()->with('success', $registrationQuestion->is_active ? 'Question switched on.' : 'Question switched off. Existing answers are kept.');
    }

    public function destroy(Event $event, RegistrationQuestion $registrationQuestion): RedirectResponse
    {
        $this->authorize('update', $event);

        if ($registrationQuestion->isBuiltIn()) {
            return back()->with('error', 'This is a standard question: switch it off instead.');
        }

        if ($registrationQuestion->answers()->exists()) {
            return back()->with('error', 'People have answered this question, so it can only be switched off. Their answers are kept.');
        }

        $label = $registrationQuestion->label;
        $registrationQuestion->delete();
        Audit::record('registration_question.deleted', eventId: $event->id, before: ['label' => $label]);

        return back()->with('success', 'Question deleted.');
    }

    public function updatePageTitles(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'titles' => ['required', 'array', 'size:2'],
            'titles.*' => ['required', 'string', 'max:200'],
        ]);

        $event->forceFill(['registration_page_titles' => array_values($data['titles'])])->save();
        Audit::record('registration_form.titles_updated', subject: $event, eventId: $event->id, after: ['titles' => $data['titles']]);

        return back()->with('success', 'Step titles saved.');
    }

    /** Replaces this event's questions with a copy of another event's, before anyone has answered. */
    public function copyFrom(Request $request, Event $event): RedirectResponse
    {
        $this->authorize('update', $event);

        $sourceId = $request->validate(['source_event_id' => ['required', 'integer']])['source_event_id'];
        $source = Event::visibleTo(Auth::user())->whereKeyNot($event->id)->find($sourceId);
        abort_unless($source, 404);

        $questions = $event->registrationQuestions()->pluck('id');
        if (RegistrationAnswer::whereIn('registration_question_id', $questions)->exists()) {
            return back()->with('error', 'People have already registered with this form, so it can no longer be replaced. Edit it instead.');
        }

        DB::transaction(function () use ($event, $source) {
            $event->registrationQuestions()->delete();

            foreach ($this->form->questions($source, activeOnly: false) as $q) {
                $event->registrationQuestions()->create($q->only(['key', 'page', 'type', 'label', 'help_text', 'options', 'settings', 'required', 'is_active', 'sort_order']));
            }

            $event->forceFill(['registration_page_titles' => array_values($this->form->pageTitles($source))])->save();
        });

        Audit::record('registration_form.copied', subject: $event, eventId: $event->id, after: ['from_event_id' => $source->id]);

        return back()->with('success', "Copied the registration form from {$source->name}.");
    }

    /** What attendees will see, walked through step by step; nothing is submitted. */
    public function preview(Event $event, RegistrationController $registration): Response
    {
        $this->authorize('view', $event);

        return Inertia::render('Public/Register', [
            ...$registration->formProps($event),
            'preview' => true,
            'authUser' => null,
            'prefillVoucherCode' => null,
            'prefillReferralPartnerId' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?RegistrationQuestion $question): array
    {
        $builtIn = $question?->isBuiltIn() ?? false;

        $data = $request->validate([
            // A built-in question keeps its type (its answers feed fixed columns).
            'type' => [$builtIn ? 'prohibited' : ($question ? 'sometimes' : 'required'), Rule::in(RegistrationQuestion::TYPES)],
            'page' => ['required', Rule::in(RegistrationForm::PAGES)],
            'label' => ['required', 'string', 'max:500'],
            'help_text' => ['nullable', 'string', 'max:1000'],
            'required' => ['boolean'],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*.id' => ['nullable', 'string', 'max:20'],
            'options.*.label' => ['required', 'string', 'max:200'],
            'options.*.hidden' => ['boolean'],
            'settings' => ['nullable', 'array'],
            'settings.display' => ['nullable', Rule::in(['checkboxes', 'radio', 'dropdown'])],
            'settings.max_selections' => ['nullable', 'integer', 'min:1', 'max:50'],
            'settings.min' => ['nullable', 'numeric'],
            'settings.max' => ['nullable', 'numeric'],
            'settings.max_kb' => ['nullable', 'integer', 'min:100', 'max:'.RegistrationForm::FILE_MAX_KB],
        ]);

        $min = $data['settings']['min'] ?? null;
        $max = $data['settings']['max'] ?? null;
        if ($min !== null && $max !== null && $max < $min) {
            abort(back()->withErrors(['settings.max' => 'The highest value must be at least the lowest.']));
        }

        $type = $question?->type ?? $data['type'];
        if ($question && ! $builtIn && isset($data['type']) && $data['type'] !== $question->type && $question->answers()->exists()) {
            abort(back()->withErrors(['type' => 'People have answered this question, so its type can no longer change.']));
        }
        $type = $builtIn ? $question->type : ($data['type'] ?? $type);

        $isChoice = in_array($type, RegistrationQuestion::CHOICE_TYPES, true);
        $options = $isChoice ? $this->mergeOptions($question?->options ?? [], $data['options'] ?? []) : null;

        if ($isChoice && ! collect($options)->where('hidden', false)->count()) {
            abort(back()->withErrors(['options' => 'Give at least one option people can choose.']));
        }

        return [
            'type' => $type,
            'page' => (int) $data['page'],
            'label' => $data['label'],
            'help_text' => $data['help_text'] ?? null,
            'required' => (bool) ($data['required'] ?? false),
            'options' => $options,
            'settings' => array_filter($data['settings'] ?? [], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * Options keep their ids; new ones get one. An existing option left out
     * of the list is kept, hidden: answers may point at it.
     */
    private function mergeOptions(array $existing, array $incoming): array
    {
        $byId = collect($existing)->keyBy('id');
        $result = [];

        foreach ($incoming as $option) {
            $id = $option['id'] ?? null;
            $result[] = [
                'id' => $id && $byId->has($id) ? $id : RegistrationForm::newOptionId(),
                'label' => trim($option['label']),
                'hidden' => (bool) ($option['hidden'] ?? false),
            ];
        }

        $kept = collect($result)->pluck('id');
        foreach ($existing as $option) {
            if (! $kept->contains($option['id'])) {
                $result[] = [...$option, 'hidden' => true];
            }
        }

        return $result;
    }

    /** @return list<array{value: string, label: string}> */
    public static function typeOptions(): array
    {
        return [
            ['value' => 'single_choice', 'label' => 'Choice: pick one'],
            ['value' => 'multi_choice', 'label' => 'Choice: pick any'],
            ['value' => 'yes_no', 'label' => 'Yes / No'],
            ['value' => 'short_text', 'label' => 'Short text'],
            ['value' => 'long_text', 'label' => 'Long text'],
            ['value' => 'number', 'label' => 'Number'],
            ['value' => 'date', 'label' => 'Date'],
            ['value' => 'file', 'label' => 'File upload'],
        ];
    }
}
