<?php

namespace Tests\Unit;

use App\Models\Announcement;
use App\Models\Connection;
use App\Models\ConsentRecord;
use App\Models\EventRegistration;
use App\Models\Feedback;
use App\Models\Payment;
use App\Models\Poll;
use App\Models\Question;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Stage G — every model factory must produce a persisted, valid row on its
 * own (relationships included), so tests never have to hand-build one.
 */
class FactoriesTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    public function test_every_factory_creates_a_persisted_row(): void
    {
        foreach ([
            EventRegistration::class, Payment::class, Ticket::class, Question::class,
            Poll::class, Feedback::class, Connection::class, Announcement::class, ConsentRecord::class,
        ] as $model) {
            $instance = $model::factory()->create();

            $this->assertTrue($instance->exists, "{$model}::factory() did not persist");
            $this->assertDatabaseHas($instance->getTable(), ['id' => $instance->getKey()]);
        }
    }

    public function test_event_registration_factory_assigns_a_public_id(): void
    {
        $this->assertNotEmpty(EventRegistration::factory()->create()->public_id);
    }

    public function test_ticket_factory_encrypts_the_code_and_derives_a_lookup_hash(): void
    {
        $ticket = Ticket::factory()->create(['code' => 'ABCD-12345678']);

        $this->assertNotSame('ABCD-12345678', $ticket->getRawOriginal('code'));
        $this->assertSame('ABCD-12345678', $ticket->fresh()->code);
        $this->assertNotEmpty($ticket->code_hash);
    }

    public function test_paid_in_person_helper_builds_a_confirmed_ticket_and_approved_payment(): void
    {
        $world = $this->registerPaidInPersonAttendee($this->makeEvent());

        $this->assertSame('approved', $world['payment']->status->value);
        $this->assertSame('confirmed', $world['ticket']->status->value);
        $this->assertSame('in_person', $world['registration']->attendance_mode->value);
    }

    public function test_manager_helper_assigns_the_manager_to_the_event(): void
    {
        $event = $this->makeEvent();
        $manager = $this->makeManagerFor($event);

        $this->assertTrue($event->managers()->where('users.id', $manager->id)->exists());
    }
}
