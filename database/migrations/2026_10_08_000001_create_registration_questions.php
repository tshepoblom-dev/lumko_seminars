<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-event registration questions (App\Services\RegistrationForm).
 *
 * - registration_questions: an event's questions on the two editable
 *   registration steps (page 1 = "interests", page 2 = "improvement").
 *   Three built-in ones carry a `key` (interests, improvement_area,
 *   organisation_challenge); their answers are also mirrored into the
 *   old event_registrations columns so networking, filters and stats keep
 *   reading those. Choice options are {id, label, hidden}: the id is
 *   stable, so renaming a label never orphans answers, and an option is
 *   hidden rather than removed.
 * - registration_answers: one per registration and question, with the
 *   question text and a readable answer as the attendee saw them.
 * - events.registration_page_titles: the two steps' headings.
 *
 * Backfill: every existing event gets the three built-in questions from
 * config (today's wording), and every existing registration's answers are
 * copied over from the old columns.
 */
return new class extends Migration
{
    private const PAGE_TITLES = [
        'What would you most like to get from this session?',
        'Where would you like to improve?',
    ];

    public function up(): void
    {
        Schema::create('registration_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('key', 40)->nullable();
            $table->unsignedTinyInteger('page')->default(1);
            $table->string('type', 20);
            $table->string('label', 500);
            $table->string('help_text', 1000)->nullable();
            $table->json('options')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'key']);
            $table->index(['event_id', 'page', 'sort_order']);
        });

        Schema::create('registration_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $table->foreignId('registration_question_id')->constrained('registration_questions')->restrictOnDelete();
            $table->json('value')->nullable();
            $table->text('display')->nullable();
            $table->string('question_label', 500);
            $table->timestamps();

            $table->unique(['event_registration_id', 'registration_question_id'], 'registration_answers_unique');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->json('registration_page_titles')->nullable();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('registration_page_titles');
        });

        Schema::dropIfExists('registration_answers');
        Schema::dropIfExists('registration_questions');
    }

    private function backfill(): void
    {
        $now = now();

        foreach (DB::table('events')->pluck('id') as $eventId) {
            DB::table('events')->where('id', $eventId)->update(['registration_page_titles' => json_encode(self::PAGE_TITLES)]);

            $interests = $this->options(config('seminar.interests', []));
            $improvement = $this->options(config('seminar.improvement_areas', []));

            $questions = [
                'interests' => ['page' => 1, 'type' => 'multi_choice', 'label' => 'Topics you are interested in', 'options' => $interests, 'sort' => 0],
                'improvement_area' => ['page' => 2, 'type' => 'multi_choice', 'label' => 'Areas you would like to improve', 'options' => $improvement, 'sort' => 0],
                'organisation_challenge' => ['page' => 2, 'type' => 'long_text', 'label' => 'A technology challenge your organisation is facing', 'options' => null, 'sort' => 1],
            ];

            $ids = [];
            foreach ($questions as $key => $q) {
                $ids[$key] = DB::table('registration_questions')->insertGetId([
                    'event_id' => $eventId,
                    'key' => $key,
                    'page' => $q['page'],
                    'type' => $q['type'],
                    'label' => $q['label'],
                    'options' => $q['options'] !== null ? json_encode($q['options']) : null,
                    'settings' => json_encode([]),
                    'required' => false,
                    'is_active' => true,
                    'sort_order' => $q['sort'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('event_registrations')->where('event_id', $eventId)
                ->select(['id', 'interests', 'improvement_area', 'organisation_challenge'])
                ->orderBy('id')
                ->chunk(500, function ($registrations) use (&$interests, &$improvement, $ids, $questions, $now) {
                    foreach ($registrations as $registration) {
                        $this->backfillChoices($registration->id, $registration->interests, $interests, $ids['interests'], $questions['interests']['label'], $now);
                        $this->backfillChoices($registration->id, $registration->improvement_area, $improvement, $ids['improvement_area'], $questions['improvement_area']['label'], $now);

                        if (filled($registration->organisation_challenge)) {
                            $this->insertAnswer($registration->id, $ids['organisation_challenge'], $questions['organisation_challenge']['label'],
                                $registration->organisation_challenge, $registration->organisation_challenge, $now);
                        }
                    }
                });

            // Unknown labels found in old answers were added as hidden options.
            DB::table('registration_questions')->where('id', $ids['interests'])->update(['options' => json_encode($interests)]);
            DB::table('registration_questions')->where('id', $ids['improvement_area'])->update(['options' => json_encode($improvement)]);
        }
    }

    /** @return list<array{id: string, label: string, hidden: bool}> */
    private function options(array $labels): array
    {
        return array_map(fn ($label) => ['id' => Str::lower(Str::random(10)), 'label' => (string) $label, 'hidden' => false], array_values($labels));
    }

    private function backfillChoices(int $registrationId, ?string $json, array &$options, int $questionId, string $questionLabel, $now): void
    {
        $labels = json_decode((string) $json, true) ?: [];
        if (! $labels) {
            return;
        }

        $chosen = [];
        foreach ($labels as $label) {
            $chosen[] = $this->optionId($options, (string) $label);
        }

        $this->insertAnswer($registrationId, $questionId, $questionLabel, $chosen, implode('; ', $labels), $now);
    }

    private function optionId(array &$options, string $label): string
    {
        foreach ($options as $option) {
            if ($option['label'] === $label) {
                return $option['id'];
            }
        }

        $option = ['id' => Str::lower(Str::random(10)), 'label' => $label, 'hidden' => true];
        $options[] = $option;

        return $option['id'];
    }

    private function insertAnswer(int $registrationId, int $questionId, string $label, mixed $value, string $display, $now): void
    {
        DB::table('registration_answers')->insert([
            'event_registration_id' => $registrationId,
            'registration_question_id' => $questionId,
            'value' => json_encode($value),
            'display' => $display,
            'question_label' => $label,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
