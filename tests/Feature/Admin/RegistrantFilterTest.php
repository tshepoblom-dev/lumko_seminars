<?php

namespace Tests\Feature\Admin;

use App\Enums\ConsentType;
use App\Models\EventRegistration;
use App\Services\ConsentService;
use App\Support\RegistrantFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CreatesEventWorld;
use Tests\TestCase;

class RegistrantFilterTest extends TestCase
{
    use CreatesEventWorld, RefreshDatabase;

    private function ids($event, array $filters): array
    {
        return RegistrantFilter::apply(EventRegistration::where('event_id', $event->id), RegistrantFilter::sanitize($filters), $event)
            ->pluck('id')->sort()->values()->all();
    }

    public function test_sanitize_drops_unknown_keys_blank_values_and_values_outside_the_option_lists(): void
    {
        $this->assertEquals(
            ['mode' => 'online', 'industry' => 'Retail', 'checked_in_only' => true],
            RegistrantFilter::sanitize([
                'mode' => 'online',
                'industry' => 'Retail',
                'organisation_size' => 'enormous',
                'payment_status' => '',
                'checked_in_only' => 'true',
                'wants_follow_up' => 'false',
                'role' => 'super_admin',
            ]),
        );
    }

    public function test_industry_size_interest_and_account_filters(): void
    {
        $event = $this->makeEvent();
        $a = $this->registerAttendee($event);
        $a->update(['industry' => 'Education', 'organisation_size' => '11-50', 'interests' => ['AI', 'Data governance']]);
        $b = $this->registerAttendee($event, 'online', ['email_verified_at' => null]);
        $b->update(['industry' => 'Education', 'organisation_size' => '1000+', 'interests' => ['Automation']]);
        $c = $this->registerAttendee($event);
        $c->update(['industry' => 'Retail']);

        $this->assertSame([$a->id, $b->id], $this->ids($event, ['industry' => 'Education']));
        $this->assertSame([$a->id], $this->ids($event, ['organisation_size' => '11-50']));
        $this->assertSame([$a->id], $this->ids($event, ['interest' => 'Data governance']));
        $this->assertSame([$b->id], $this->ids($event, ['account_set_up' => '0']));
        $this->assertSame([$a->id, $c->id], $this->ids($event, ['account_set_up' => '1']));
    }

    // Lumko marketing consent lives only in the ledger; the attendee list's
    // filter used to read the (never-written) projection and matched no one.
    public function test_lumko_opt_in_filter_reads_the_consent_ledger(): void
    {
        $event = $this->makeEvent();
        $admin = $this->makeSuperAdmin();
        $optedIn = $this->registerAttendee($event);
        app(ConsentService::class)->grant($optedIn->user, ConsentType::LumkoMarketing, null, 'test', effectiveImmediately: true);
        $pending = $this->registerAttendee($event);
        app(ConsentService::class)->grant($pending->user, ConsentType::LumkoMarketing, null, 'test'); // not yet effective
        $never = $this->registerAttendee($event);

        $this->assertSame([$optedIn->id], $this->ids($event, ['lumko_opt_in' => '1']));
        $this->assertSame([$pending->id, $never->id], $this->ids($event, ['lumko_opt_in' => '0']));

        $this->actingAs($admin)->get(route('admin.attendees.index', [$event, 'lumko_opt_in' => '1']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('attendees.data', 1)
                ->where('attendees.data.0.id', $optedIn->id)
                ->where('attendees.data.0.user_id', $optedIn->user_id)
                ->where('total', 1));
    }
}
