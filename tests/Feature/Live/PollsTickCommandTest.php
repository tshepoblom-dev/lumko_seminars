<?php

namespace Tests\Feature\Live;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollsTickCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_tick_opens_a_scheduled_poll_and_closes_any_other_open_poll(): void
    {
        $event = Event::factory()->create();
        $alreadyOpen = Poll::create(['event_id' => $event->id, 'question' => 'A', 'status' => 'open']);
        $scheduled = Poll::create(['event_id' => $event->id, 'question' => 'B', 'status' => 'draft', 'opens_at' => now()->subMinute()]);
        $notYet = Poll::create(['event_id' => $event->id, 'question' => 'C', 'status' => 'draft', 'opens_at' => now()->addHour()]);

        $this->artisan('polls:tick')->assertExitCode(0);

        $this->assertSame('open', $scheduled->fresh()->status->value);
        $this->assertSame('closed', $alreadyOpen->fresh()->status->value);
        $this->assertSame('draft', $notYet->fresh()->status->value);
    }

    public function test_tick_closes_a_poll_past_its_scheduled_close_time(): void
    {
        $event = Event::factory()->create();
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'A', 'status' => 'open', 'closes_at' => now()->subMinute()]);

        $this->artisan('polls:tick')->assertExitCode(0);

        $this->assertSame('closed', $poll->fresh()->status->value);
    }

    public function test_tick_is_idempotent(): void
    {
        $event = Event::factory()->create();
        $poll = Poll::create(['event_id' => $event->id, 'question' => 'A', 'status' => 'draft', 'opens_at' => now()->subMinute()]);

        $this->artisan('polls:tick');
        $this->artisan('polls:tick');

        $this->assertSame(1, AuditLog::where('action', 'poll.opened')->where('subject_id', $poll->id)->count());
    }
}
