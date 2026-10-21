<script setup>
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import PortalPage from '@/Components/PortalPage.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { longDate } from '@/lib/eventFormat';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    partner: { type: Object, required: true },
    events: { type: Array, required: true },
    canManageTeam: { type: Boolean, default: false },
});

const has = (event, feature) => event.features.includes(feature);
const sourceLabels = { connect: 'Connect button', enquiry: 'Enquiries', badge_scan: 'Stand scans', registration: 'At registration' };
</script>

<template>
    <PartnerLayout :partner="partner">
        <Head :title="`${partner.name} · Partner portal`" />

        <PortalPage eyebrow="Partner portal" :title="partner.name" width="wide">
            <section aria-labelledby="profile-check" class="grid gap-6 border-b border-rule pb-10 lg:grid-cols-12">
                <h2 id="profile-check" class="eyebrow text-ink-500 lg:col-span-3">Your profile</h2>
                <div class="lg:col-span-9">
                    <ul class="flex flex-wrap gap-x-8 gap-y-3">
                        <li><Badge :variant="partner.has_description ? 'success' : 'warning'">{{ partner.has_description ? 'Description added' : 'No description yet' }}</Badge></li>
                        <li>
                            <Badge :variant="!partner.has_logo ? 'warning' : partner.logo_approved ? 'success' : 'info'">
                                {{ !partner.has_logo ? 'No logo yet' : partner.logo_approved ? 'Logo approved' : 'Logo awaiting approval' }}
                            </Badge>
                        </li>
                    </ul>
                    <p class="mt-3 max-w-prose text-sm text-ink-600">
                        Attendees see your logo, description and website on the event site and your partner page.
                        <Link :href="route('partner.profile.edit', partner.slug)" class="link">Edit your profile</Link>
                        <template v-if="canManageTeam"> · <Link :href="route('partner.team.index', partner.slug)" class="link">Invite your team</Link></template>
                    </p>
                </div>
            </section>

            <section v-if="!events.length" class="py-16">
                <h2 class="display text-display-sm text-ink-900">No events yet</h2>
                <p class="mt-3 max-w-prose text-[0.9375rem] text-ink-600">When our team adds you as a partner of an event, it appears here.</p>
            </section>

            <section
                v-for="event in events"
                :key="event.id"
                :aria-labelledby="`event-${event.id}`"
                class="grid gap-6 border-b border-rule py-10 lg:grid-cols-12"
            >
                <div class="lg:col-span-3">
                    <p class="eyebrow text-ink-500">{{ event.has_ended ? 'Past event' : 'Upcoming' }}</p>
                    <p class="tnum mt-2 text-sm text-ink-700">{{ longDate(event.starts_at) }}</p>
                    <p class="mt-1 text-sm text-ink-500">{{ event.tier ? `${event.tier} partner` : 'Tier not set yet' }}</p>
                    <p v-if="event.stand_label" class="mt-1 text-sm text-ink-500">Stand: {{ event.stand_label }}</p>
                    <p class="mt-2"><Badge :variant="event.is_published ? 'success' : 'neutral'">{{ event.is_published ? 'Shown on the event site' : 'Not on the event site yet' }}</Badge></p>
                </div>

                <div class="lg:col-span-9">
                    <h2 :id="`event-${event.id}`" class="display text-display-sm text-ink-900">{{ event.name }}</h2>

                    <dl class="mt-6 grid grid-cols-2 gap-px border border-rule bg-rule sm:grid-cols-5">
                        <div class="bg-white p-4">
                            <dt class="text-meta text-ink-500">Leads</dt>
                            <dd class="tnum mt-1 font-display text-3xl text-ink-900">{{ event.leads.total }}</dd>
                        </div>
                        <div v-for="(count, source) in event.leads.by_source" :key="source" class="bg-white p-4">
                            <dt class="text-meta text-ink-500">{{ sourceLabels[source] }}</dt>
                            <dd class="tnum mt-1 text-xl text-ink-900">{{ count }}</dd>
                        </div>
                    </dl>

                    <p v-if="event.referrals" class="mt-4 text-sm text-ink-600">
                        Referrals: <strong class="tnum font-semibold text-ink-900">{{ event.referrals.referred }}</strong> registered naming you,
                        <strong class="tnum font-semibold text-ink-900">{{ event.referrals.converted }}</strong> became clients.
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <Button size="sm" :href="route('partner.events.leads.index', [partner.slug, event.id])">Your leads</Button>
                        <Button v-if="has(event, 'leads_scan')" size="sm" variant="secondary" :href="route('partner.events.scan.show', [partner.slug, event.id])">Scan at your stand</Button>
                        <Button v-if="has(event, 'referrals')" size="sm" variant="secondary" :href="route('partner.events.referrals', [partner.slug, event.id])">Referral link</Button>
                        <Button v-if="has(event, 'analytics')" size="sm" variant="secondary" :href="route('partner.events.analytics', [partner.slug, event.id])">Analytics</Button>
                        <Button v-if="has(event, 'resources')" size="sm" variant="secondary" :href="route('partner.events.resources.index', [partner.slug, event.id])">Resources</Button>
                        <Button v-if="has(event, 'comp_tickets')" size="sm" variant="secondary" :href="route('partner.events.tickets.index', [partner.slug, event.id])">Complimentary tickets</Button>
                    </div>

                    <p class="mt-6 text-meta text-ink-500">
                        Your partnership includes: {{ event.feature_labels.length ? event.feature_labels.join(', ') : 'nothing yet (our team will set your tier)' }}.
                    </p>
                </div>
            </section>
        </PortalPage>
    </PartnerLayout>
</template>
