<?php

namespace App\Console\Commands;

use App\Support\Legal;
use App\Enums\ConsentType;
use App\Enums\PartnerFeature;
use App\Models\Announcement;
use App\Models\Event;
use App\Models\EventPartner;
use App\Models\Partner;
use App\Models\PartnerTier;
use App\Models\Payment;
use App\Models\Poll;
use App\Models\Question;
use App\Models\Resource;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Voucher;
use App\Services\ConsentService;
use App\Support\VoucherCode;
use Illuminate\Console\Command;

/**
 * Stage G — fixture data for the Playwright browser suite.
 * Deliberately refuses to run outside `testing`: this creates known
 * email/password/ticket-code combinations, which must never exist in a
 * real environment.
 */
class E2eSeedCommand extends Command
{
    protected $signature = 'e2e:seed';

    protected $description = 'Seed known-credential fixtures for the Playwright suite (testing environment only)';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('Refusing to run outside the testing environment.');

            return self::FAILURE;
        }

        $event = Event::where('slug', 'the-power-of-data')->firstOrFail();
        // Agora as the provider, so the host console and the player's real
        // "join" state exist to be scanned (no credentials: nothing connects).
        $event->update(['livestream_provider' => 'agora', 'livestream_channel_name' => 'e2e-channel']);
        // The browser suite registers online in several specs; the live
        // event is in person only. zy-in-person-only.spec turns this off
        // and back on again.
        $event->update(['offers_online' => true]);
        $admin = User::where('email', 'admin@lumko.cloud')->firstOrFail();
        $consents = app(ConsentService::class);

        $online = $this->makeUser('e2e-attendee@example.test', 'E2E', 'Attendee');
        $online->registrations()->firstOrCreate(
            ['event_id' => $event->id],
            ['attendance_mode' => 'online', 'status' => 'registered']
        );

        // Registered online, kept for the in-person-only switch (zy-in-person-only.spec).
        $switcher = $this->makeUser('e2e-switcher@example.test', 'E2E', 'Switcher');
        $switcher->registrations()->firstOrCreate(
            ['event_id' => $event->id],
            ['attendance_mode' => 'online', 'status' => 'registered']
        );

        $checkIn = $this->makeUser('e2e-checkin@example.test', 'E2E', 'CheckIn');
        $registration = $checkIn->registrations()->firstOrCreate(
            ['event_id' => $event->id],
            ['attendance_mode' => 'in_person', 'status' => 'registered']
        );
        Payment::updateOrCreate(
            ['event_registration_id' => $registration->id],
            ['amount' => $event->in_person_price, 'status' => 'approved']
        );
        Ticket::updateOrCreate(
            ['event_registration_id' => $registration->id],
            ['code' => 'E2ETEST1', 'status' => 'confirmed', 'issued_at' => now()]
        );

        // In person, payment still pending (the proof-upload page).
        $pending = $this->makeUser('e2e-pending@example.test', 'E2E', 'Pending');
        $pendingRegistration = $pending->registrations()->firstOrCreate(
            ['event_id' => $event->id],
            ['attendance_mode' => 'in_person', 'status' => 'registered']
        );
        Payment::updateOrCreate(
            ['event_registration_id' => $pendingRegistration->id],
            ['amount' => $event->in_person_price, 'status' => 'pending']
        );

        // Signed in but email not verified (the /verify-email page).
        $this->makeUser('e2e-unverified@example.test', 'E2E', 'Unverified', verified: false);

        // A confirmed account that never registered for the event (Admin →
        // Attendees lists it; a guest registering this email is saved to it).
        $this->makeUser('e2e-accountonly@example.test', 'E2E', 'AccountOnly');

        // Networking opt-in for two attendees so the directory has someone in it.
        foreach ([$online, $checkIn] as $user) {
            if (! $consents->isGranted($user, ConsentType::Networking, $event)) {
                $consents->grant($user, ConsentType::Networking, $event, source: 'registration', effectiveImmediately: true);
            }
        }

        // Content for the admin index/edit pages and the attendee pages.
        Announcement::firstOrCreate(
            ['event_id' => $event->id, 'title' => 'E2E announcement'],
            [
                'created_by' => $admin->id, 'body' => 'A published announcement for the browser suite.',
                'type' => 'event_update', 'audience' => 'all', 'published_at' => now()->subMinute(),
            ]
        );
        Resource::firstOrCreate(
            ['event_id' => $event->id, 'title' => 'E2E slides'],
            [
                'created_by' => $admin->id, 'description' => 'Slides for the browser suite.', 'category' => 'before_event',
                'external_url' => 'https://example.com/slides', 'is_published' => true, 'release_mode' => 'immediate',
            ]
        );
        $poll = Poll::firstOrCreate(
            ['event_id' => $event->id, 'question' => 'E2E poll: which topic next?'],
            ['created_by' => $admin->id, 'status' => 'draft', 'show_results_live' => false]
        );
        if ($poll->options()->count() === 0) {
            foreach (['Automation', 'Data governance', 'AI'] as $i => $label) {
                $poll->options()->create(['label' => $label, 'sort_order' => $i]);
            }
        }
        Question::firstOrCreate(
            ['event_id' => $event->id, 'body' => 'E2E pending question?'],
            ['user_id' => $online->id, 'status' => 'submitted', 'normalized_hash' => hash('sha256', 'e2e pending question?')]
        );
        Question::firstOrCreate(
            ['event_id' => $event->id, 'body' => 'E2E approved question?'],
            ['user_id' => $checkIn->id, 'status' => 'approved', 'normalized_hash' => hash('sha256', 'e2e approved question?')]
        );

        // Invitation codes (vouchers), with fixed codes the specs can type:
        // one reserved for an online invitee (redeemed from their account),
        // one anyone can use (redeemed on the registration form), and one
        // left unused for the admin list.
        $invitee = $this->makeUser('e2e-invitee@example.test', 'E2E', 'Invitee');
        $invitee->registrations()->firstOrCreate(
            ['event_id' => $event->id],
            ['attendance_mode' => 'online', 'status' => 'registered']
        );
        foreach ([
            'E2E0-VCHR-0001' => ['recipient_name' => 'E2E Invitee', 'recipient_email' => 'e2e-invitee@example.test', 'email_locked' => true],
            'E2E0-VCHR-0002' => ['recipient_name' => 'E2E Guest', 'recipient_email' => null, 'email_locked' => false],
            'E2E0-VCHR-0003' => ['recipient_name' => 'E2E Unused', 'recipient_email' => 'e2e-unused@example.test', 'email_locked' => true],
        ] as $code => $attributes) {
            if (! Voucher::where('code_hash', VoucherCode::hash($code))->exists()) {
                $voucher = new Voucher(['event_id' => $event->id, 'created_by' => $admin->id, ...$attributes]);
                $voucher->code = $code;
                $voucher->save();
            }
        }

        // The speaker portal: an account linked to the event's first speaker.
        $speakerUser = $this->makeUser('e2e-speaker@example.test', 'E2E', 'Speaker');
        $speaker = $event->speakers()->first();
        if ($speaker && ! $speaker->user_id) {
            $speaker->forceFill(['user_id' => $speakerUser->id])->save();
        }

        // The partner portal: a published partner on a tier with every
        // feature, an owner, and one lead (the online attendee connected).
        $partner = Partner::firstOrCreate(
            ['slug' => 'e2e-partner-co'],
            ['name' => 'E2E Partner Co', 'website' => 'https://partner.example', 'description' => 'A partner for the browser suite.'],
        );
        $tier = PartnerTier::firstOrCreate(
            ['event_id' => $event->id, 'name' => 'Gold'],
            ['sort_order' => 0, 'features' => PartnerFeature::values(), 'comp_tickets' => 2],
        );
        EventPartner::firstOrCreate(
            ['event_id' => $event->id, 'partner_id' => $partner->id],
            ['partner_tier_id' => $tier->id, 'is_published' => true, 'stand_label' => 'Stand 1', 'blurb' => 'Visit us at Stand 1.'],
        );
        $partnerOwner = $this->makeUser('e2e-partner@example.test', 'E2E', 'Partner');
        $partner->members()->syncWithoutDetaching([$partnerOwner->id => ['role' => 'owner']]);
        if (! $consents->isGranted($online, ConsentType::PartnerContact, $event, partner: $partner)) {
            $consents->grant($online, ConsentType::PartnerContact, $event, source: 'connect', effectiveImmediately: true,
                note: 'Interested in your data platform', partner: $partner);
        }

        // Every seeded account (admin included) has accepted the current
        // Terms & Privacy Policy; the legal gate is exercised separately.
        User::all()->each(fn (User $user) => Legal::recordAcceptance($user, 'seed'));

        // …except this one: an existing attendee from before the documents
        // existed, who must accept them on first sign-in.
        $noTerms = $this->makeUser('e2e-noterms@example.test', 'E2E', 'NoTerms');
        $noTerms->legalAcceptances()->delete();
        $noTerms->registrations()->firstOrCreate(
            ['event_id' => $event->id],
            ['attendance_mode' => 'online', 'status' => 'registered']
        );

        $this->components->info('E2E fixtures seeded.');

        return self::SUCCESS;
    }

    private function makeUser(string $email, string $first, string $last, bool $verified = true): User
    {
        // email_verified_at is deliberately not mass-assignable on User.
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last, 'password' => bcrypt('Password123!')]
        );
        $user->forceFill(['email_verified_at' => $verified ? now() : null])->save();

        return $user;
    }
}
