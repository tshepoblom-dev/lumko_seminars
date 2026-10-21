<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\Feedback;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

/**
 * Everything that belongs to one event lives under /admin/events/{event}:
 * reachable for staff of that event, closed to everyone else, and only
 * ever showing that event's data.
 */
class EventWorkspaceTest extends TestCase
{
    use CreatesEventWorld;
    use RefreshDatabase;

    private const SIDEBAR_ROUTES = [
        'admin.events.show',
        'admin.programme-sessions.index',
        'admin.events.speakers.index',
        'admin.resources.index',
        'admin.attendees.index',
        'admin.payments.index',
        'admin.check-in.index',
        'admin.announcements.index',
        'admin.live.control-room',
        'admin.live.broadcast.show',
        'admin.live.questions.index',
        'admin.live.polls.index',
        'admin.feedback.index',
        'admin.engagement.show',
        'admin.events.edit',
    ];

    public function test_an_assigned_manager_can_open_every_workspace_page(): void
    {
        $event = $this->makeEvent();
        $manager = $this->makeManagerFor($event);

        foreach (self::SIDEBAR_ROUTES as $name) {
            $this->actingAs($manager)->get(route($name, $event))->assertOk();
        }
    }

    public function test_an_unassigned_manager_is_refused_every_workspace_page(): void
    {
        $event = $this->makeEvent();
        $manager = $this->makeManagerFor($this->makeEvent());

        foreach (self::SIDEBAR_ROUTES as $name) {
            $this->actingAs($manager)->get(route($name, $event))->assertForbidden();
        }
    }

    public function test_workspace_pages_share_the_event_and_the_switcher_list(): void
    {
        $event = $this->makeEvent(['name' => 'The Power of Data']);
        $manager = $this->makeManagerFor($event);
        $this->makeEvent(['name' => 'Someone else\'s event']);

        $this->actingAs($manager)
            ->get(route('admin.payments.index', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.event.id', $event->id)
                ->where('workspace.event.name', 'The Power of Data')
                // Only events this manager can see are offered in the switcher.
                ->has('workspace.events', 1)
            );
    }

    public function test_cross_event_pages_have_no_workspace(): void
    {
        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.speakers.index'))
            ->assertInertia(fn (Assert $page) => $page->missing('workspace'));
    }

    public function test_lists_only_show_the_workspace_events_data(): void
    {
        $event = $this->makeEvent();
        $other = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        $this->registerPaidInPersonAttendee($event);
        $this->registerPaidInPersonAttendee($other);
        Announcement::factory()->create(['event_id' => $event->id]);
        Announcement::factory()->create(['event_id' => $other->id]);
        Resource::factory()->create(['event_id' => $event->id, 'external_url' => 'https://example.com/a']);
        Resource::factory()->create(['event_id' => $other->id, 'external_url' => 'https://example.com/b']);
        Feedback::create(['event_id' => $event->id, 'user_id' => User::factory()->create()->id, 'rating' => 5]);
        Feedback::create(['event_id' => $other->id, 'user_id' => User::factory()->create()->id, 'rating' => 1]);

        $this->actingAs($admin)->get(route('admin.payments.index', $event))
            ->assertInertia(fn (Assert $page) => $page->has('payments', 1));
        $this->actingAs($admin)->get(route('admin.announcements.index', $event))
            ->assertInertia(fn (Assert $page) => $page->has('announcements', 1));
        $this->actingAs($admin)->get(route('admin.resources.index', $event))
            ->assertInertia(fn (Assert $page) => $page->has('resources', 1));
        $this->actingAs($admin)->get(route('admin.feedback.index', $event))
            ->assertInertia(fn (Assert $page) => $page->has('feedback', 1)->where('feedback.0.rating', 5));
    }

    public function test_a_record_from_another_event_is_not_found_inside_this_workspace(): void
    {
        $event = $this->makeEvent();
        $other = $this->makeEvent();
        ['payment' => $payment] = $this->registerPaidInPersonAttendee($other, approved: false);
        $announcement = Announcement::factory()->create(['event_id' => $other->id]);

        $admin = $this->makeSuperAdmin();
        $this->actingAs($admin)->get(route('admin.payments.show', [$event, $payment]))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.announcements.edit', [$event, $announcement]))->assertNotFound();
    }

    public function test_the_event_comes_from_the_url_not_the_form(): void
    {
        $event = $this->makeEvent();
        $other = $this->makeEvent();

        $this->actingAs($this->makeSuperAdmin())->post(route('admin.announcements.store', $event), [
            'event_id' => $other->id, // ignored
            'title' => 'Parking',
            'body' => 'Use the north gate.',
            'type' => 'event_update',
            'audience' => 'all',
        ])->assertRedirect(route('admin.announcements.index', $event));

        $this->assertSame($event->id, Announcement::where('title', 'Parking')->value('event_id'));
    }

    public function test_old_top_level_urls_redirect_into_the_default_events_workspace(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin)->get('/admin/payments')->assertRedirect(route('admin.payments.index', $event));
        $this->actingAs($admin)->get('/admin/programme-sessions')->assertRedirect(route('admin.programme-sessions.index', $event));
        $this->actingAs($admin)->get('/admin/live/control-room')->assertRedirect(route('admin.live.control-room', $event));
    }

    public function test_old_urls_go_to_the_event_list_when_there_is_no_event(): void
    {
        $this->actingAs($this->makeSuperAdmin())->get('/admin/payments')->assertRedirect(route('admin.events.index'));
    }

    public function test_overview_flags_what_needs_attention(): void
    {
        $event = $this->makeEvent();
        $this->registerAttendee($event, userAttributes: ['email_verified_at' => null]);
        ['payment' => $payment] = $this->registerPaidInPersonAttendee($event, approved: false);
        $payment->update(['status' => 'under_review']);

        $this->actingAs($this->makeSuperAdmin())
            ->get(route('admin.events.show', $event))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Events/Show')
                ->where('stats.registered_total', 2)
                ->where('attention.accounts_not_set_up', 1)
                ->where('attention.payments_to_review', 1)
            );
    }

    public function test_a_manager_who_creates_an_event_can_work_on_it(): void
    {
        $manager = $this->makeManagerFor($this->makeEvent());

        $this->actingAs($manager)->post(route('admin.events.store'), [
            'name' => 'Second seminar',
            'slug' => 'second-seminar',
            'starts_at' => now()->addMonth()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addMonth()->addHour()->format('Y-m-d H:i:s'),
            'online_price' => 0,
            'in_person_price' => 0,
            'status' => 'draft',
        ])->assertSessionHasNoErrors();

        $created = \App\Models\Event::where('slug', 'second-seminar')->firstOrFail();
        $this->actingAs($manager)->get(route('admin.events.show', $created))->assertOk();
    }
}
