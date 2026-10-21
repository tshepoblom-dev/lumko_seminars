<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    event: { type: Object, required: true },
});

// Everything that belongs to one event, grouped the way it's used:
// set-up before the day, people and money, communication, then the live
// day and what comes after it. `match` is a Ziggy wildcard.
const groups = computed(() => [
    {
        label: null,
        items: [{ label: 'Overview', route: 'admin.events.show', match: 'admin.events.show' }],
    },
    {
        label: 'Plan',
        items: [
            { label: 'Programme', route: 'admin.programme-sessions.index', match: 'admin.programme-sessions.*' },
            { label: 'Registration form', route: 'admin.events.registration-form.index', match: 'admin.events.registration-form.*' },
            { label: 'Speakers', route: 'admin.events.speakers.index', match: 'admin.events.speakers.*' },
            { label: 'Partners', route: 'admin.events.partners.index', match: 'admin.events.partners.*' },
            { label: 'Resources', route: 'admin.resources.index', match: 'admin.resources.*' },
        ],
    },
    {
        label: 'People',
        items: [
            { label: 'Attendees', route: 'admin.attendees.index', match: 'admin.attendees.*' },
            { label: 'Payments', route: 'admin.payments.index', match: 'admin.payments.*' },
            { label: 'Vouchers', route: 'admin.vouchers.index', match: 'admin.vouchers.*' },
            { label: 'Check-in', route: 'admin.check-in.index', match: 'admin.check-in.*' },
            { label: 'Announcements', route: 'admin.announcements.index', match: 'admin.announcements.*' },
            { label: 'Communications', route: 'admin.communications.index', match: 'admin.communications.*' },
        ],
    },
    {
        label: 'Live',
        items: [
            { label: 'Control room', route: 'admin.live.control-room', match: 'admin.live.control-room' },
            { label: 'Broadcast', route: 'admin.live.broadcast.show', match: 'admin.live.broadcast.*' },
            { label: 'Q&A', route: 'admin.live.questions.index', match: 'admin.live.questions.*' },
            { label: 'Polls', route: 'admin.live.polls.index', match: 'admin.live.polls.*' },
            // A new tab, so opening it never takes the Broadcast console off air.
            { label: 'Attendee view', route: 'admin.live.preview', match: 'admin.live.preview', newTab: true },
        ],
    },
    {
        label: 'After',
        items: [
            { label: 'Feedback', route: 'admin.feedback.index', match: 'admin.feedback.*' },
            { label: 'Engagement', route: 'admin.engagement.show', match: 'admin.engagement.*' },
        ],
    },
    {
        label: null,
        items: [{ label: 'Settings', route: 'admin.events.edit', match: 'admin.events.edit' }],
    },
]);

const isActive = (item) => route().current(item.match);
</script>

<template>
    <nav aria-label="Event" class="text-sm">
        <div v-for="(group, gi) in groups" :key="gi" :class="gi > 0 ? 'mt-4' : ''">
            <p v-if="group.label" class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-600">
                {{ group.label }}
            </p>
            <ul class="space-y-0.5">
                <li v-for="item in group.items" :key="item.route">
                    <a
                        v-if="item.newTab"
                        :href="route(item.route, { event: props.event.id })"
                        target="_blank"
                        rel="noopener"
                        class="block rounded px-3 py-1.5 text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                    >
                        {{ item.label }}<span aria-hidden="true"> &#8599;&#xFE0E;</span><span class="sr-only"> (opens in a new tab)</span>
                    </a>
                    <Link
                        v-else
                        :href="route(item.route, { event: props.event.id })"
                        :aria-current="isActive(item) ? 'page' : undefined"
                        :class="[
                            'block rounded px-3 py-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand',
                            isActive(item) ? 'bg-brand font-medium text-white' : 'text-gray-700 hover:bg-gray-100',
                        ]"
                    >
                        {{ item.label }}
                    </Link>
                </li>
            </ul>
        </div>
    </nav>
</template>
