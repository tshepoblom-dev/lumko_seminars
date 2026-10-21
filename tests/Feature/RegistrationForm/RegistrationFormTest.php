<?php

namespace Tests\Feature\RegistrationForm;

use App\Actions\AccountAnonymiser;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\RegistrationAnswer;
use App\Models\RegistrationQuestion;
use App\Services\EventStatsService;
use App\Services\RegistrationForm;
use App\Support\RegistrantFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/** Per-event registration questions: the form, validation, storage, editing and where answers show up. */
class RegistrationFormTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function form(): RegistrationForm
    {
        return app(RegistrationForm::class);
    }

    /** The one open event (registration takes the earliest published, not-ended event). */
    private function openEvent(): Event
    {
        $event = $this->makeEvent();
        $this->form()->ensureDefaults($event);

        return $event;
    }

    private function question(Event $event, array $attributes): RegistrationQuestion
    {
        return $event->registrationQuestions()->create([
            'page' => 1, 'sort_order' => 10, 'required' => false, 'label' => 'A question', ...$attributes,
        ]);
    }

    private function sector(Event $event, bool $required = true): RegistrationQuestion
    {
        return $this->question($event, [
            'type' => 'single_choice', 'label' => 'Which sector are you in?', 'required' => $required,
            'options' => [['id' => 'pub', 'label' => 'Public', 'hidden' => false], ['id' => 'pri', 'label' => 'Private', 'hidden' => false], ['id' => 'old', 'label' => 'Retired option', 'hidden' => true]],
        ]);
    }

    private function payload(array $answers = [], array $overrides = []): array
    {
        return [
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo@example.com',
            'organisation' => 'ABC Municipality', 'job_title' => 'IT Manager', 'attendance_mode' => 'online',
            'networking_consent' => false, 'lumko_marketing_consent' => false, 'accept_legal' => true,
            'answers' => $answers,
            ...$overrides,
        ];
    }

    /** A guest registers (a fresh email each time: registration is rate-limited per address). */
    private function register(array $answers): EventRegistration
    {
        Notification::fake();
        auth()->logout();
        $this->post(route('event.register.store'), $this->payload($answers, ['email' => 'guest'.uniqid().'@example.com']))
            ->assertRedirect(route('event.register.confirmation'))
            ->assertSessionHasNoErrors();

        return EventRegistration::latest('id')->firstOrFail();
    }

    public function test_an_event_gets_the_three_standard_questions_with_todays_options(): void
    {
        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin)->post(route('admin.events.store'), [
            'name' => 'Data Day', 'slug' => 'data-day', 'starts_at' => now()->addMonth()->toDateTimeString(), 'ends_at' => now()->addMonth()->addHours(3)->toDateTimeString(),
            'online_price' => 0, 'in_person_price' => 500, 'status' => 'draft',
        ])->assertSessionHasNoErrors();

        $event = Event::where('name', 'Data Day')->sole();
        $questions = $event->registrationQuestions()->get();

        $this->assertSame(['interests', 'improvement_area', 'organisation_challenge'], $questions->pluck('key')->all());
        $this->assertSame([1, 1, 1], $questions->pluck('page')->all());
        $this->assertSame(config('seminar.interests'), array_column($questions[0]->options, 'label'));
        $this->assertSame(RegistrationForm::DEFAULT_PAGE_TITLES, $event->fresh()->registration_page_titles);
    }

    public function test_the_migration_backfill_turns_old_columns_into_answers(): void
    {
        $event = $this->makeEvent();
        $registration = $this->registerAttendee($event);
        $registration->update(['interests' => ['AI', 'Something retired'], 'organisation_challenge' => 'Legacy systems']);
        DB::table('registration_questions')->delete();

        $migration = require database_path('migrations/2026_10_08_000001_create_registration_questions.php');
        (fn () => $this->backfill())->call($migration);

        $interests = $event->registrationQuestions()->where('key', 'interests')->sole();
        $answer = RegistrationAnswer::where('registration_question_id', $interests->id)->sole();
        $this->assertSame('AI; Something retired', $answer->display);
        $retired = collect($interests->options)->firstWhere('label', 'Something retired');
        $this->assertTrue($retired['hidden']);
        $this->assertContains($retired['id'], $answer->value);

        $challenge = $event->registrationQuestions()->where('key', 'organisation_challenge')->sole();
        $this->assertSame('Legacy systems', RegistrationAnswer::where('registration_question_id', $challenge->id)->sole()->display);
    }

    public function test_the_form_has_one_question_step_with_active_questions_and_visible_options(): void
    {
        $event = $this->openEvent();
        $this->sector($event);
        $this->question($event, ['type' => 'short_text', 'label' => 'Switched off', 'is_active' => false]);
        $event->registrationQuestions()->where('key', 'improvement_area')->update(['is_active' => false]);

        // Step 4 has the standard questions; step 5 is empty by default.
        $this->get(route('event.register'))->assertInertia(fn (Assert $page) => $page
            ->component('Public/Register')
            ->has('questionPages', 2)
            ->has('questionPages.0.questions', 3)
            ->where('questionPages.0.questions.0.label', 'Topics you are interested in')
            ->where('questionPages.0.questions.1.label', 'A technology challenge your organisation is facing')
            ->where('questionPages.0.questions.2.label', 'Which sector are you in?')
            ->has('questionPages.0.questions.2.options', 2)
            ->where('questionPages.1.title', 'A few more questions')
            ->has('questionPages.1.questions', 0));

        // A step-5 question shows on step 5. The old step-5 title saved on
        // existing events reads as the new default.
        $this->question($event, ['type' => 'short_text', 'label' => 'Dietary requirements', 'page' => 2]);
        $event->forceFill(['registration_page_titles' => ['What would you most like to get from this session?', 'Where would you like to improve?']])->save();
        $this->get(route('event.register'))->assertInertia(fn (Assert $page) => $page
            ->where('questionPages.1.title', 'A few more questions')
            ->has('questionPages.1.questions', 1)
            ->where('questionPages.1.questions.0.label', 'Dietary requirements'));
    }

    public function test_a_step_5_question_is_checked_on_step_5_and_saved(): void
    {
        $event = $this->openEvent();
        $later = $this->question($event, ['type' => 'short_text', 'label' => 'Dietary requirements', 'page' => 2, 'required' => true]);

        $this->postJson(route('event.register.validate-step'), ['step' => 4, 'answers' => []])->assertOk();
        $this->postJson(route('event.register.validate-step'), ['step' => 5, 'answers' => []])
            ->assertJsonValidationErrors("answers.{$later->id}");

        $registration = $this->register([$later->id => 'Vegetarian']);
        $this->assertSame('Vegetarian', $registration->answers()->sole()->display);
    }

    public function test_reordering_stays_within_the_questions_step(): void
    {
        $event = $this->openEvent();
        $first = $this->question($event, ['type' => 'short_text', 'label' => 'Step 5 first', 'page' => 2, 'sort_order' => 0]);
        $second = $this->question($event, ['type' => 'short_text', 'label' => 'Step 5 second', 'page' => 2, 'sort_order' => 1]);

        $this->actingAs($this->makeSuperAdmin())
            ->post(route('admin.events.registration-form.questions.move', [$event, $second]), ['direction' => 'up']);

        $this->assertSame(['Step 5 second', 'Step 5 first'], $event->registrationQuestions()->where('page', 2)->pluck('label')->all());
        $this->assertSame(3, $event->registrationQuestions()->where('page', 1)->count());
    }

    public function test_each_type_is_validated(): void
    {
        $event = $this->openEvent();
        $sector = $this->sector($event);
        $many = $this->question($event, ['type' => 'multi_choice', 'settings' => ['max_selections' => 1],
            'options' => [['id' => 'a', 'label' => 'A', 'hidden' => false], ['id' => 'b', 'label' => 'B', 'hidden' => false]]]);
        $count = $this->question($event, ['type' => 'number', 'settings' => ['min' => 1, 'max' => 100]]);
        $when = $this->question($event, ['type' => 'date']);
        $agree = $this->question($event, ['type' => 'yes_no', 'required' => true]);

        $step = fn (array $answers) => $this->postJson(route('event.register.validate-step'), ['step' => 4, 'answers' => $answers]);

        $step([])->assertStatus(422)->assertJsonValidationErrors(["answers.{$sector->id}", "answers.{$agree->id}"]);
        // A hidden option isn't accepted any more.
        $step([$sector->id => 'old', $agree->id => true])->assertJsonValidationErrors("answers.{$sector->id}");
        $step([$sector->id => 'pub', $agree->id => false, $many->id => ['a', 'b']])->assertJsonValidationErrors("answers.{$many->id}");
        $step([$sector->id => 'pub', $agree->id => false, $count->id => 500])->assertJsonValidationErrors("answers.{$count->id}");
        $step([$sector->id => 'pub', $agree->id => false, $when->id => 'not a date'])->assertJsonValidationErrors("answers.{$when->id}");
        // "No" is an answer.
        $step([$sector->id => 'pub', $agree->id => false, $many->id => ['a'], $count->id => 12, $when->id => '2026-12-01'])->assertOk();

        // Errors name the question.
        $this->assertSame('Please answer “Which sector are you in?”.', $step([])->json('errors')["answers.{$sector->id}"][0]);
        $this->assertSame('Choose one of the options for “Which sector are you in?”.', $step([$sector->id => 'old', $agree->id => true])->json('errors')["answers.{$sector->id}"][0]);
    }

    public function test_answers_are_stored_with_what_the_attendee_saw(): void
    {
        $event = $this->openEvent();
        $sector = $this->sector($event);
        $role = $this->question($event, ['type' => 'short_text', 'label' => 'Your role in the project']);

        $registration = $this->register([$sector->id => 'pub', $role->id => '  Data lead ']);

        $answers = $registration->answers()->get()->keyBy('registration_question_id');
        $this->assertSame('pub', $answers[$sector->id]->value);
        $this->assertSame('Public', $answers[$sector->id]->display);
        $this->assertSame('Data lead', $answers[$role->id]->display);

        // Later edits change the form, not the answer.
        $this->actingAs($this->makeSuperAdmin())->put(route('admin.events.registration-form.questions.update', [$event, $sector]), [
            'page' => 1, 'label' => 'Your sector', 'required' => true,
            'options' => [['id' => 'pub', 'label' => 'Public sector'], ['id' => 'pri', 'label' => 'Private']],
        ])->assertSessionHas('success');

        $answer = $answers[$sector->id]->fresh();
        $this->assertSame('Which sector are you in?', $answer->question_label);
        $this->assertSame('Public', $answer->display);
        // Left out of the list = hidden, kept (answers may point at it).
        $this->assertTrue(collect($sector->fresh()->options)->firstWhere('id', 'old')['hidden']);
        $this->assertSame('Public sector', $sector->fresh()->optionLabel('pub'));
    }

    public function test_a_file_answer_is_checked_on_submit_stored_privately_and_downloaded_by_staff_only(): void
    {
        Storage::fake('local');
        $event = $this->openEvent();
        $cv = $this->question($event, ['type' => 'file', 'label' => 'Your company profile', 'required' => true, 'settings' => ['max_kb' => 1024]]);

        // The JSON step check can't carry a file, so it doesn't ask for one.
        $this->postJson(route('event.register.validate-step'), ['step' => 4, 'answers' => []])->assertOk();

        Notification::fake();
        $this->post(route('event.register.store'), $this->payload([], ['email' => 'a@example.com']))->assertSessionHasErrors("answers.{$cv->id}");
        $this->post(route('event.register.store'), $this->payload([$cv->id => UploadedFile::fake()->create('evil.html', 10, 'text/html')], ['email' => 'b@example.com']))
            ->assertSessionHasErrors("answers.{$cv->id}");
        $this->post(route('event.register.store'), $this->payload([$cv->id => UploadedFile::fake()->create('big.pdf', 2048, 'application/pdf')], ['email' => 'c@example.com']))
            ->assertSessionHasErrors("answers.{$cv->id}");

        $registration = $this->register([$cv->id => UploadedFile::fake()->create('profile.pdf', 100, 'application/pdf')]);
        $answer = $registration->answers()->sole();
        $this->assertSame('profile.pdf', $answer->display);
        Storage::disk('local')->assertExists($answer->filePath());

        $this->actingAs($this->registerAttendee($event)->user)
            ->get(route('admin.attendees.answers.file', [$event, $registration, $answer]))->assertForbidden();

        $this->actingAs($this->makeManagerFor($event))
            ->get(route('admin.attendees.answers.file', [$event, $registration, $answer]))
            ->assertOk()
            ->assertDownload('profile.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration_answer.file_downloaded']);
    }

    public function test_deleting_an_account_removes_written_and_uploaded_answers_but_keeps_choices(): void
    {
        Storage::fake('local');
        $event = $this->openEvent();
        $sector = $this->sector($event);
        $role = $this->question($event, ['type' => 'short_text']);
        $file = $this->question($event, ['type' => 'file']);

        $registration = $this->register([
            $sector->id => 'pri', $role->id => 'CIO',
            $file->id => UploadedFile::fake()->create('profile.pdf', 10, 'application/pdf'),
        ]);
        $path = $registration->answers()->where('registration_question_id', $file->id)->sole()->filePath();

        app(AccountAnonymiser::class)->anonymise($registration->user);

        $left = $registration->answers()->get();
        $this->assertSame([$sector->id], $left->pluck('registration_question_id')->all());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_answered_questions_can_only_be_switched_off_and_standard_ones_keep_their_type(): void
    {
        $event = $this->openEvent();
        $sector = $this->sector($event, required: false);
        $unused = $this->question($event, ['type' => 'short_text', 'label' => 'Never answered']);
        $this->register([$sector->id => 'pub']);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->delete(route('admin.events.registration-form.questions.destroy', [$event, $sector]))->assertSessionHas('error');
        $this->assertModelExists($sector);
        $this->actingAs($admin)->delete(route('admin.events.registration-form.questions.destroy', [$event, $unused]))->assertSessionHas('success');
        $this->assertModelMissing($unused);

        $this->actingAs($admin)->post(route('admin.events.registration-form.questions.toggle', [$event, $sector]))->assertSessionHas('success');
        $this->assertFalse($sector->fresh()->is_active);
        $this->assertSame(1, $sector->answers()->count());

        $interests = $event->registrationQuestions()->where('key', 'interests')->sole();
        $this->actingAs($admin)->delete(route('admin.events.registration-form.questions.destroy', [$event, $interests]))->assertSessionHas('error');
        $this->actingAs($admin)->put(route('admin.events.registration-form.questions.update', [$event, $interests]), [
            'type' => 'short_text', 'page' => 1, 'label' => 'Topics',
        ])->assertSessionHasErrors('type');
        $this->assertSame('multi_choice', $interests->fresh()->type);
    }

    public function test_copying_a_form_works_only_before_anyone_has_answered(): void
    {
        $source = $this->makeEvent(['name' => 'Last year', 'starts_at' => now()->subYear(), 'ends_at' => now()->subYear()->addHours(3), 'status' => 'completed']);
        $this->form()->ensureDefaults($source);
        $this->sector($source);
        $event = $this->openEvent();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->post(route('admin.events.registration-form.copy', $event), ['source_event_id' => $source->id])->assertSessionHas('success');
        $this->assertSame(4, $event->registrationQuestions()->count());
        $copied = $event->registrationQuestions()->where('label', 'Which sector are you in?')->sole();

        $this->register([$copied->id => 'pub']);
        $this->actingAs($admin)->post(route('admin.events.registration-form.copy', $event), ['source_event_id' => $source->id])->assertSessionHas('error');
        $this->assertModelExists($copied);
    }

    public function test_only_staff_for_the_event_edit_its_form(): void
    {
        $event = $this->openEvent();
        $other = $this->makeEvent(['starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addHours(2)]);
        $data = ['type' => 'short_text', 'page' => 1, 'label' => 'Sneaky'];

        $this->actingAs($this->makeManagerFor($other))->post(route('admin.events.registration-form.questions.store', $event), $data)->assertForbidden();
        $this->actingAs($this->registerAttendee($event)->user)->post(route('admin.events.registration-form.questions.store', $event), $data)->assertForbidden();
        $this->actingAs($this->makeManagerFor($event))->post(route('admin.events.registration-form.questions.store', $event), $data)->assertSessionHas('success');

        $this->assertSame(1, $event->registrationQuestions()->where('label', 'Sneaky')->count());
    }

    public function test_answer_filters_select_the_same_people_in_the_list_the_export_and_communications(): void
    {
        $event = $this->openEvent();
        $sector = $this->sector($event, required: false);
        $agree = $this->question($event, ['type' => 'yes_no', 'label' => 'Need parking?']);
        $public = $this->registerAttendee($event, 'online', ['name' => 'Public Person', 'email' => 'public@example.com']);
        $private = $this->registerAttendee($event, 'online', ['name' => 'Private Person', 'email' => 'private@example.com']);
        $this->form()->save($public, [$sector->id => 'pub', $agree->id => true]);
        $this->form()->save($private, [$sector->id => 'pri', $agree->id => false]);

        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin)->get(route('admin.attendees.index', [$event, 'answers' => [$sector->id => 'pub']]))
            ->assertInertia(fn (Assert $page) => $page->where('total', 1)->where('attendees.data.0.name', 'Public Person')
                ->has('formFilters.questions', 2));

        $csv = $this->actingAs($admin)->get(route('admin.attendees.export', [$event, 'answers' => [$agree->id => 'no']]))->streamedContent();
        $this->assertStringContainsString('private@example.com', $csv);
        $this->assertStringNotContainsString('public@example.com', $csv);
        $this->assertStringContainsString('Which sector are you in?', $csv);
        $this->assertStringContainsString(',Private,No', $csv);

        // An option the event doesn't have is ignored, not an error.
        $this->assertSame([], RegistrantFilter::sanitize(['answers' => [$sector->id => 'nope']], $event));
        $filters = RegistrantFilter::sanitize(['answers' => [$sector->id => 'pri']], $event);
        $this->assertSame(['private@example.com'], RegistrantFilter::apply(EventRegistration::where('event_id', $event->id), $filters, $event)
            ->with('user')->get()->pluck('user.email')->all());
    }

    public function test_the_dashboard_counts_choice_answers(): void
    {
        $event = $this->openEvent();
        $sector = $this->sector($event, required: false);
        foreach (['pub', 'pub', 'pri'] as $choice) {
            $this->form()->save($this->registerAttendee($event), [$sector->id => $choice]);
        }

        $stats = app(EventStatsService::class)->dashboardExtras($event);
        $this->assertSame([['label' => 'Public', 'count' => 2], ['label' => 'Private', 'count' => 1]], $stats['by_question'][0]['rows']);
    }

    public function test_staff_can_preview_the_form_without_submitting(): void
    {
        $event = $this->makeEvent(['status' => 'draft']);

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.events.registration-form.preview', $event))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Public/Register')->where('preview', true)->has('questionPages', 2));

        $this->actingAs($this->makeSuperAdmin())->get(route('admin.events.registration-form.index', $event))
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Events/RegistrationForm')->has('questions', 3)->where('hasAnswers', false));
    }
}
