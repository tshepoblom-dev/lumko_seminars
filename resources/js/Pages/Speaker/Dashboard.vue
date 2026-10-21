<script setup>
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import PortalPage from '@/Components/PortalPage.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { longDate, timeRange } from '@/lib/eventFormat';
import { speakerNav } from '@/lib/portalNav';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    speaker: { type: Object, required: true },
    events: { type: Array, required: true },
});

const upcoming = computed(() => props.events.filter((e) => !e.has_ended));
const past = computed(() => props.events.filter((e) => e.has_ended));

const photoState = computed(() => {
    if (!props.speaker.photo_url) return { variant: 'neutral', label: 'No photo yet' };
    return props.speaker.photo_approved
        ? { variant: 'success', label: 'Photo approved' }
        : { variant: 'warning', label: 'Photo awaiting approval' };
});

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
</script>

<template>
    <PortalLayout area="Speaker portal" current="speaker" :home-href="route('speaker.dashboard')" :nav="speakerNav()">
        <Head title="Speaker portal" />

        <PortalPage eyebrow="Speaker portal" :title="`Welcome, ${speaker.name}`" width="wide">
            <template #aside>
                <p>{{ [speaker.title, speaker.organisation].filter(Boolean).join(', ') }}</p>
            </template>

            <section aria-labelledby="profile-check" class="grid gap-6 border-b border-rule pb-10 lg:grid-cols-12">
                <h2 id="profile-check" class="eyebrow text-ink-500 lg:col-span-3">Your profile</h2>
                <div class="lg:col-span-9">
                    <ul class="flex flex-wrap gap-x-8 gap-y-3">
                        <li><Badge :variant="speaker.has_bio ? 'success' : 'warning'">{{ speaker.has_bio ? 'Bio added' : 'No bio yet' }}</Badge></li>
                        <li><Badge :variant="photoState.variant">{{ photoState.label }}</Badge></li>
                    </ul>
                    <p class="mt-3 max-w-prose text-sm text-ink-600">
                        Attendees see your name, title, organisation, bio and photo on the event site.
                        <Link :href="route('speaker.profile.edit')" class="link">Edit your profile</Link>
                    </p>
                </div>
            </section>

            <section v-if="!events.length" class="py-16">
                <h2 class="display text-display-sm text-ink-900">No events yet</h2>
                <p class="mt-3 max-w-prose text-[0.9375rem] text-ink-600">
                    Once our team adds you to an event's line-up and the event is announced, your sessions appear here.
                </p>
            </section>

            <section
                v-for="event in [...upcoming, ...past]"
                :key="event.id"
                :aria-labelledby="`event-${event.id}`"
                class="grid gap-6 border-b border-rule py-10 lg:grid-cols-12"
            >
                <div class="lg:col-span-3">
                    <p class="eyebrow text-ink-500">{{ event.has_ended ? 'Past event' : event.is_live ? 'Live now' : 'Upcoming' }}</p>
                    <p class="tnum mt-2 text-sm text-ink-700">{{ longDate(event.starts_at) }}</p>
                    <p v-if="event.venue_name" class="mt-1 text-sm text-ink-500">{{ event.venue_name }}</p>
                </div>

                <div class="lg:col-span-9">
                    <h2 :id="`event-${event.id}`" class="display text-display-sm text-ink-900">{{ event.name }}</h2>

                    <h3 class="mt-6 text-sm font-semibold text-ink-900">Your sessions</h3>
                    <ul v-if="event.sessions.length" class="mt-2 divide-y divide-rule border-y border-rule">
                        <li v-for="s in event.sessions" :key="s.id" class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr]">
                            <span class="tnum text-sm text-ink-600">{{ timeRange(s.start_time, s.end_time) }}</span>
                            <span>
                                <span class="font-medium text-ink-900">{{ s.title }}</span>
                                <span v-if="s.with.length" class="block text-sm text-ink-500">With {{ s.with.join(', ') }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-ink-600">The programme hasn't placed you in a session yet.</p>

                    <div class="mt-8 grid gap-6 sm:grid-cols-2">
                        <div v-if="event.has_stage" class="border-t-2 border-ink-900 pt-4">
                            <h3 class="font-semibold text-ink-900">Livestream</h3>
                            <p class="mt-1 text-sm text-ink-600">
                                Test your camera, mic and connection in the green room (attendees can't see it), then use your stage to present.
                            </p>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <Button size="sm" :href="route('speaker.events.green-room', event.id)">Test your setup</Button>
                                <Button size="sm" variant="secondary" :href="route('speaker.events.stage', event.id)" native>Open your stage</Button>
                            </div>
                        </div>
                        <div v-else-if="!event.has_ended" class="border-t-2 border-ink-900 pt-4">
                            <h3 class="font-semibold text-ink-900">Livestream</h3>
                            <p class="mt-1 text-sm text-ink-600">This event isn't presented through the platform's stage. Our team will brief you on the set-up.</p>
                        </div>

                        <div class="border-t-2 border-ink-900 pt-4">
                            <h3 class="font-semibold text-ink-900">Resources</h3>
                            <p class="mt-1 text-sm text-ink-600">
                                {{ event.resources_count ? `You've shared ${plural(event.resources_count, 'resource', 'resources')}.` : 'Share slides, papers or links with attendees.' }}
                            </p>
                            <div class="mt-4">
                                <Button size="sm" variant="secondary" :href="route('speaker.events.resources.index', event.id)">Manage resources</Button>
                            </div>
                        </div>

                        <div class="border-t-2 border-ink-900 pt-4">
                            <h3 class="font-semibold text-ink-900">Connections &amp; report</h3>
                            <p class="mt-1 text-sm text-ink-600">
                                {{ plural(event.connections_count, 'person has', 'people have') }} chosen to connect with you. Your report also covers your session's questions, polls and audience.
                            </p>
                            <div class="mt-4">
                                <Button size="sm" variant="secondary" :href="route('speaker.events.report', event.id)" native>Open your report</Button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </PortalPage>
    </PortalLayout>
</template>
