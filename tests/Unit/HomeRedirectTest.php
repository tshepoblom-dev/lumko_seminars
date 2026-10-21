<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\Partner;
use App\Models\Speaker;
use App\Models\User;
use App\Support\HomeRedirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-BOOT-3: post-login / verify / confirm redirects are role aware. Speaker
 * and partner portals come from the account's links (speakers.user_id,
 * partner_members), so these users are persisted.
 */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_go_to_the_admin_dashboard(): void
    {
        $user = User::factory()->create(['role' => UserRole::EventManager]);

        $this->assertSame(route('admin.dashboard', absolute: false), HomeRedirect::for($user));
    }

    public function test_attendees_go_to_the_attendee_dashboard(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);

        $this->assertSame(route('attendee.dashboard', absolute: false), HomeRedirect::for($user));
    }

    public function test_speakers_go_to_the_speaker_portal(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        Speaker::factory()->create(['user_id' => $user->id]);

        $this->assertSame(route('speaker.dashboard', absolute: false), HomeRedirect::for($user));
    }

    public function test_partner_team_members_go_to_their_partner_portal(): void
    {
        $user = User::factory()->create(['role' => UserRole::Attendee]);
        $partner = Partner::create(['name' => 'DataWorks']);
        $partner->members()->attach($user->id, ['role' => 'member']);

        $this->assertSame(route('partner.dashboard', $partner, absolute: false), HomeRedirect::for($user));
    }

    public function test_guests_go_home(): void
    {
        $this->assertSame(route('home', absolute: false), HomeRedirect::for(null));
    }
}
