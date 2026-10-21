<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\RegistrationAnswer;
use App\Models\RegistrationQuestion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The one place that knows an event's registration questions: the two
 * editable steps of the registration form (page 1 = step 4, holding the
 * standard questions; page 2 = step 5, an optional second step that is
 * skipped while it has no questions), what the form renders, how each
 * answer is validated and stored, and the per-event filter options.
 *
 * Answers to the three built-in questions (`key` interests,
 * improvement_area, organisation_challenge) are also written to the
 * event_registrations columns of the same name — as option labels, like
 * before — so networking, the interest filters, the dashboard's top lists,
 * the data export and the anonymiser keep reading those unchanged.
 */
class RegistrationForm
{
    public const PAGES = [1, 2];

    public const DEFAULT_PAGE_TITLES = [
        'What would you most like to get from this session?',
        'A few more questions',
    ];

    /**
     * Step 5's title before it became an open "more questions" step; the
     * backfill saved it on existing events. Read as "not set".
     */
    private const OLD_STEP_5_TITLE = 'Where would you like to improve?';

    /** File answers: kilobytes, default and ceiling. */
    public const FILE_DEFAULT_KB = 5120;

    public const FILE_MAX_KB = 10240;

    private const FILE_MIMES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg';

    private const FILE_MIMETYPES = 'application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,image/png,image/jpeg';

    /** The three built-in questions, from config, for an event that has none. */
    public function ensureDefaults(Event $event): void
    {
        if ($event->registrationQuestions()->exists()) {
            return;
        }

        DB::transaction(function () use ($event) {
            $event->registrationQuestions()->create([
                'key' => 'interests', 'page' => 1, 'type' => 'multi_choice', 'sort_order' => 0,
                'label' => 'Topics you are interested in',
                'options' => self::optionsFromLabels(config('seminar.interests', [])),
            ]);
            $event->registrationQuestions()->create([
                'key' => 'improvement_area', 'page' => 1, 'type' => 'multi_choice', 'sort_order' => 1,
                'label' => 'Areas you would like to improve',
                'options' => self::optionsFromLabels(config('seminar.improvement_areas', [])),
            ]);
            $event->registrationQuestions()->create([
                'key' => 'organisation_challenge', 'page' => 1, 'type' => 'long_text', 'sort_order' => 2,
                'label' => 'A technology challenge your organisation is facing',
            ]);

            if (! $event->registration_page_titles) {
                $event->forceFill(['registration_page_titles' => self::DEFAULT_PAGE_TITLES])->save();
            }
        });
    }

    /** @return list<array{id: string, label: string, hidden: bool}> */
    public static function optionsFromLabels(array $labels): array
    {
        return array_map(fn ($label) => ['id' => self::newOptionId(), 'label' => (string) $label, 'hidden' => false], array_values($labels));
    }

    public static function newOptionId(): string
    {
        return Str::lower(Str::random(10));
    }

    /** @return Collection<int, RegistrationQuestion> */
    public function questions(Event $event, bool $activeOnly = true): Collection
    {
        $this->ensureDefaults($event);

        return $event->registrationQuestions()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->get();
    }

    public function pageTitles(Event $event): array
    {
        $titles = $event->registration_page_titles ?: [];
        $second = $titles[1] ?? null;

        return [
            1 => $titles[0] ?? self::DEFAULT_PAGE_TITLES[0],
            2 => ($second && $second !== self::OLD_STEP_5_TITLE) ? $second : self::DEFAULT_PAGE_TITLES[1],
        ];
    }

    /**
     * What the registration form renders: per page, its title and active
     * questions with visible options only. A page with no questions is
     * skipped by the stepper.
     */
    public function schema(Event $event): array
    {
        $questions = $this->questions($event);
        $titles = $this->pageTitles($event);

        return collect(self::PAGES)->map(fn ($page) => [
            'page' => $page,
            'title' => $titles[$page],
            'questions' => $questions->where('page', $page)->values()->map(fn (RegistrationQuestion $q) => [
                'id' => $q->id,
                'type' => $q->type,
                'label' => $q->label,
                'help_text' => $q->help_text,
                'required' => $q->required,
                'options' => $q->isChoice() ? $q->visibleOptions() : [],
                'settings' => [
                    'display' => $q->setting('display', 'checkboxes'),
                    'max_selections' => $q->setting('max_selections'),
                    'min' => $q->setting('min'),
                    'max' => $q->setting('max'),
                    'max_kb' => $q->type === 'file' ? $this->fileMaxKb($q) : null,
                ],
            ])->all(),
        ])->all();
    }

    /**
     * Validation rules for one page's answers, keyed `answers.{id}`. File
     * questions are only checked on the final submit: the per-step check
     * posts JSON, which can't carry a file.
     */
    public function rules(Event $event, int $page, bool $final): array
    {
        $rules = ['answers' => ['nullable', 'array']];

        foreach ($this->questions($event)->where('page', $page) as $question) {
            $field = "answers.{$question->id}";
            $presence = $question->required ? 'required' : 'nullable';

            if ($question->type === 'file') {
                $rules[$field] = $final
                    ? [$presence, 'file', 'max:'.$this->fileMaxKb($question), 'mimes:'.self::FILE_MIMES, 'mimetypes:'.self::FILE_MIMETYPES]
                    : ['nullable'];

                continue;
            }

            $optionIds = array_column($question->visibleOptions(), 'id');

            $rules += match ($question->type) {
                'single_choice' => [$field => [$presence, 'string', Rule::in($optionIds)]],
                'multi_choice' => [
                    $field => [$presence, 'array', 'max:'.(int) ($question->setting('max_selections') ?: max(1, count($optionIds)))],
                    "{$field}.*" => ['string', Rule::in($optionIds), 'distinct'],
                ],
                'short_text' => [$field => [$presence, 'string', 'max:255']],
                'long_text' => [$field => [$presence, 'string', 'max:2000']],
                'yes_no' => [$field => [$question->required ? 'required' : 'nullable', 'boolean']],
                'number' => [$field => array_values(array_filter([
                    $presence, 'numeric',
                    $question->setting('min') !== null ? 'min:'.$question->setting('min') : null,
                    $question->setting('max') !== null ? 'max:'.$question->setting('max') : null,
                ]))],
                'date' => [$field => [$presence, 'date']],
                default => [],
            };
        }

        return $rules;
    }

    /**
     * Attribute names (the question as asked, so other errors say the
     * question, not "answers.12").
     */
    public function attributes(Event $event): array
    {
        return $this->questions($event)
            ->mapWithKeys(fn (RegistrationQuestion $q) => [
                "answers.{$q->id}" => '“'.Str::limit($q->label, 80).'”',
                "answers.{$q->id}.*" => '“'.Str::limit($q->label, 80).'”',
            ])
            ->all();
    }

    /** Plain messages for the common cases, naming the question. */
    public function messages(Event $event): array
    {
        return $this->questions($event)
            ->flatMap(function (RegistrationQuestion $q) {
                $label = '“'.Str::limit($q->label, 80).'”';
                $field = "answers.{$q->id}";

                return [
                    "{$field}.required" => "Please answer {$label}.",
                    "{$field}.in" => "Choose one of the options for {$label}.",
                    "{$field}.*.in" => "Choose from the options for {$label}.",
                    "{$field}.max" => match ($q->type) {
                        'multi_choice' => "Choose at most :max options for {$label}.",
                        'file' => "The file for {$label} must be :max KB or smaller.",
                        'number' => "{$label} must be at most :max.",
                        default => "{$label} must be at most :max characters.",
                    },
                    "{$field}.min" => "{$label} must be at least :min.",
                    "{$field}.mimes" => "The file for {$label} must be a PDF, Word, Excel, PowerPoint, PNG or JPG file.",
                    "{$field}.mimetypes" => "The file for {$label} must be a PDF, Word, Excel, PowerPoint, PNG or JPG file.",
                ];
            })
            ->all();
    }

    /**
     * Stores a registration's answers (already validated), with the
     * question text and a readable answer as the attendee saw them, and
     * mirrors the built-in ones into the old columns.
     *
     * @param  array<int|string, mixed>  $answers  question id => value
     */
    public function save(EventRegistration $registration, array $answers): void
    {
        $questions = $this->questions($registration->event)->keyBy('id');
        $mirror = [];

        foreach ($questions as $id => $question) {
            $raw = $answers[$id] ?? null;

            [$value, $display] = $this->normalise($question, $raw, $registration);

            if ($question->isBuiltIn()) {
                $mirror[$question->key] = match ($question->key) {
                    'interests', 'improvement_area' => array_values(array_filter(array_map(fn ($optionId) => $question->optionLabel($optionId), (array) $value))),
                    default => $value,
                };
            }

            if ($value === null || $value === [] || $value === '') {
                $this->forget($registration, $question);

                continue;
            }

            RegistrationAnswer::updateOrCreate(
                ['event_registration_id' => $registration->id, 'registration_question_id' => $question->id],
                ['value' => $value, 'display' => $display, 'question_label' => $question->label],
            );
        }

        if ($mirror) {
            $registration->forceFill([
                'interests' => $mirror['interests'] ?? ($registration->interests ?? []),
                'improvement_area' => $mirror['improvement_area'] ?? ($registration->improvement_area ?? []),
                'organisation_challenge' => array_key_exists('organisation_challenge', $mirror) ? $mirror['organisation_challenge'] : $registration->organisation_challenge,
            ])->save();
        }
    }

    /** @return array{0: mixed, 1: ?string} the stored value and its readable form */
    private function normalise(RegistrationQuestion $question, mixed $raw, EventRegistration $registration): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [null, null];
        }

        return match ($question->type) {
            'single_choice' => [(string) $raw, $question->optionLabel((string) $raw)],
            'multi_choice' => [
                array_values(array_map('strval', (array) $raw)),
                implode('; ', array_filter(array_map(fn ($id) => $question->optionLabel((string) $id), (array) $raw))),
            ],
            'short_text', 'long_text' => [trim((string) $raw), trim((string) $raw)],
            'yes_no' => [$bool = filter_var($raw, FILTER_VALIDATE_BOOLEAN), $bool ? 'Yes' : 'No'],
            'number' => [$raw + 0, (string) ($raw + 0)],
            'date' => [$date = \Illuminate\Support\Carbon::parse($raw)->toDateString(), $date],
            'file' => $raw instanceof UploadedFile ? $this->storeFile($raw, $registration) : [null, null],
            default => [null, null],
        };
    }

    /** @return array{0: array, 1: string} */
    private function storeFile(UploadedFile $file, EventRegistration $registration): array
    {
        $path = $file->store("registration-answers/{$registration->event_id}/{$registration->id}", 'local');
        $name = Str::limit($file->getClientOriginalName(), 200, '');

        return [['path' => $path, 'name' => $name, 'size' => $file->getSize(), 'mime' => $file->getMimeType()], $name];
    }

    private function forget(EventRegistration $registration, RegistrationQuestion $question): void
    {
        $answer = RegistrationAnswer::where('event_registration_id', $registration->id)
            ->where('registration_question_id', $question->id)->first();

        if ($answer) {
            if ($path = $answer->filePath()) {
                Storage::disk('local')->delete($path);
            }
            $answer->delete();
        }
    }

    public function fileMaxKb(RegistrationQuestion $question): int
    {
        return (int) min(self::FILE_MAX_KB, max(100, (int) $question->setting('max_kb', self::FILE_DEFAULT_KB)));
    }

    /**
     * Per-event filter choices for Attendees and Communications: the
     * interests options (labels, as stored in the mirrored column) and
     * every active choice or yes/no question that isn't built in.
     */
    public function filterOptions(Event $event): array
    {
        $questions = $this->questions($event);

        return [
            'interests' => array_column($questions->firstWhere('key', 'interests')?->visibleOptions() ?? [], 'label'),
            'questions' => $questions->filter(fn (RegistrationQuestion $q) => ! $q->isBuiltIn() && $q->isFilterable())
                ->values()
                ->map(fn (RegistrationQuestion $q) => [
                    'id' => $q->id,
                    'label' => $q->label,
                    'options' => $q->type === 'yes_no'
                        ? [['id' => 'yes', 'label' => 'Yes'], ['id' => 'no', 'label' => 'No']]
                        : array_map(fn ($o) => ['id' => $o['id'], 'label' => $o['label']], $q->options ?? []),
                ])
                ->all(),
        ];
    }

    /**
     * "Topics for next time" on the feedback form: the event's interest
     * options, or the config list if the event switched that question off.
     */
    public function topicOptions(Event $event): array
    {
        $labels = array_column($this->questions($event)->firstWhere('key', 'interests')?->visibleOptions() ?? [], 'label');

        return $labels ?: config('seminar.interests', []);
    }

    /** Every interest label any event offers, for the platform-wide filter. */
    public static function allInterestLabels(): array
    {
        return RegistrationQuestion::where('key', 'interests')->pluck('options')
            ->flatMap(fn ($options) => array_column($options ?? [], 'label'))
            ->merge(config('seminar.interests', []))
            ->unique()
            ->values()
            ->all();
    }

    /** Deletes the stored files of a registration's (or a whole event's) file answers. */
    public function deleteFiles(EventRegistration|Event $subject): void
    {
        if ($subject instanceof Event) {
            Storage::disk('local')->deleteDirectory("registration-answers/{$subject->id}");

            return;
        }

        $subject->answers()->get()->each(function (RegistrationAnswer $answer) {
            if ($path = $answer->filePath()) {
                Storage::disk('local')->delete($path);
            }
        });
    }
}
