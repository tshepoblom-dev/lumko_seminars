<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PollResults from '@/Components/Live/PollResults.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { useRealtime } from '@/composables/useRealtime';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const eventRoute = useEventRoute();
const eventId = usePage().props.workspace?.event?.id;

const props = defineProps({ polls: Array });

const polls = ref([...props.polls]);
watch(() => props.polls, (p) => { polls.value = [...p]; });

// Every vote, open and close arrives here with exact counts.
const { state: realtimeState } = useRealtime(`presenters.event.${eventId}`, {
    '.poll.results': (e) => {
        const exists = polls.value.some((p) => p.id === e.poll.id);
        polls.value = exists
            ? polls.value.map((p) => (p.id === e.poll.id ? e.poll : p))
            : [e.poll, ...polls.value];
    },
}, { only: ['polls'] });

function act(action, id) {
    router.post(eventRoute(`admin.live.polls.${action}`, { poll: id }), {}, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Polls</h2>
                <p class="text-sm" :class="realtimeState === 'connected' ? 'text-green-700' : 'text-gray-600'" role="status">
                    {{ realtimeState === 'connected' ? '● Live results' : 'Refreshing every few seconds' }}
                </p>
            </div>
        </template>

        <Head title="Polls" />

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-gray-600">Presenters can also run polls from the Broadcast console and their stage link.</p>
                    <Link :href="eventRoute('admin.live.polls.create')" class="rounded bg-ink-900 px-4 py-2 text-sm text-white">
                        New poll
                    </Link>
                </div>
                <p v-if="!polls.length" class="text-sm text-gray-600">No polls yet.</p>
                <div v-for="poll in polls" :key="poll.id" class="mb-4 rounded-lg bg-white p-4 shadow">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="font-semibold">{{ poll.question }}</p>
                        <span
                            class="rounded px-2 py-0.5 text-xs font-semibold"
                            :class="{
                                'bg-green-100 text-green-800': poll.status === 'open',
                                'bg-gray-100 text-gray-700': poll.status !== 'open',
                            }"
                        >{{ poll.status === 'open' ? 'Open now' : poll.status === 'draft' ? 'Draft' : 'Closed' }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-600">
                        {{ poll.show_results_live ? 'Attendees see results while it runs' : 'Attendees see results when it closes' }}
                    </p>
                    <PollResults :poll="poll" class="mt-3" />
                    <div class="mt-3 space-x-3 text-sm">
                        <button v-if="poll.status === 'draft'" type="button" class="text-green-700" @click="act('open', poll.id)">Open</button>
                        <button v-if="poll.status === 'open'" type="button" class="text-red-700" @click="act('close', poll.id)">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
