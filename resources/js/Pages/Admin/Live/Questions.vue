<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { useRealtime } from '@/composables/useRealtime';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const eventRoute = useEventRoute();
const eventId = usePage().props.workspace?.event?.id;

const props = defineProps({
    questions: Array,
    chat: { type: Array, default: () => [] },
});

// Local copies the live events update; a reload (or the polling
// fallback) replaces them wholesale.
const questions = ref([...props.questions]);
const chat = ref([...props.chat]);
const freshIds = ref(new Set());
watch(() => props.questions, (q) => { questions.value = [...q]; });
watch(() => props.chat, (c) => { chat.value = [...c]; });

const waiting = computed(() => questions.value.filter((q) => q.status === 'submitted'));
const others = computed(() => questions.value.filter((q) => q.status !== 'submitted'));

const { state: realtimeState } = useRealtime([
    {
        name: `admin.event.${eventId}`,
        handlers: {
            '.question.pending': (e) => {
                if (questions.value.some((q) => q.id === e.id)) return;
                questions.value = [e, ...questions.value];
                freshIds.value = new Set([...freshIds.value, e.id]);
            },
        },
    },
    {
        name: `event.${eventId}`,
        handlers: {
            '.question.updated': (e) => {
                questions.value = questions.value.map((q) => {
                    if (q.id !== e.question.id) return q;
                    const { status, is_featured, votes_count } = e.question;
                    return {
                        ...q,
                        ...(status !== undefined && { status }),
                        ...(is_featured !== undefined && { is_featured }),
                        ...(votes_count !== undefined && { votes_count }),
                    };
                });
            },
            '.chat.posted': (e) => {
                if (!chat.value.some((m) => m.id === e.message.id)) chat.value = [...chat.value, e.message].slice(-100);
            },
            '.chat.hidden': (e) => {
                chat.value = chat.value.filter((m) => m.id !== e.id);
            },
        },
    },
], { only: ['questions', 'chat'] });

function act(action, id) {
    freshIds.value.delete(id);
    router.post(eventRoute(`admin.live.questions.${action}`, { question: id }), {}, { preserveScroll: true });
}

function hideChat(message) {
    if (!confirm(`Hide this message from ${message.author}? Everyone stops seeing it.`)) return;
    router.post(eventRoute('admin.live.chat.hide', { chatMessage: message.id }), {}, { preserveScroll: true });
}

const clock = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '');
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Moderate Q&amp;A and chat</h2>
                <p class="text-sm" :class="realtimeState === 'connected' ? 'text-green-700' : 'text-gray-600'" role="status">
                    {{ realtimeState === 'connected' ? '● Live' : 'Refreshing every few seconds' }}
                </p>
            </div>
        </template>

        <Head title="Moderate Q&A" />

        <div class="py-8">
            <div class="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 lg:grid-cols-5 lg:px-8">
                <section class="space-y-6 lg:col-span-3" aria-labelledby="qa-heading">
                    <h3 id="qa-heading" class="sr-only">Questions</h3>

                    <div>
                        <p class="text-sm font-semibold text-gray-700">Waiting for approval ({{ waiting.length }})</p>
                        <p v-if="!waiting.length" class="mt-2 text-sm text-gray-600">Nothing waiting. New questions appear here as they're asked.</p>
                        <div class="mt-2 space-y-3">
                            <article
                                v-for="q in waiting"
                                :key="q.id"
                                class="rounded-lg bg-white p-4 shadow"
                                :class="freshIds.has(q.id) ? 'ring-2 ring-signal-500' : ''"
                            >
                                <p>{{ q.body }}</p>
                                <p class="mt-1 text-xs text-gray-600">
                                    {{ q.user_name }}
                                    <span v-if="freshIds.has(q.id)" class="ml-1 font-semibold text-signal-700">New</span>
                                </p>
                                <div class="mt-2 space-x-3 text-sm">
                                    <button type="button" class="text-green-700" @click="act('approve', q.id)">Approve</button>
                                    <button type="button" class="text-red-700" @click="act('hide', q.id)">Hide</button>
                                </div>
                            </article>
                        </div>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-gray-700">Moderated</p>
                        <div class="mt-2 space-y-3">
                            <article v-for="q in others" :key="q.id" class="rounded-lg bg-white p-4 shadow">
                                <p>{{ q.body }}</p>
                                <p class="mt-1 text-xs text-gray-600">
                                    {{ q.user_name }} &middot; {{ q.status }} &middot; {{ q.votes_count }} {{ q.votes_count === 1 ? 'vote' : 'votes' }}
                                    <span v-if="q.is_featured"> &middot; featured</span>
                                </p>
                                <div class="mt-2 space-x-3 text-sm">
                                    <button v-if="q.status !== 'approved' && q.status !== 'answered'" type="button" class="text-green-700" @click="act('approve', q.id)">Approve</button>
                                    <button v-if="!q.is_featured && q.status !== 'hidden'" type="button" class="text-yellow-800" @click="act('feature', q.id)">Feature</button>
                                    <button v-if="q.status !== 'answered' && q.status !== 'hidden'" type="button" class="text-blue-700" @click="act('mark-answered', q.id)">Mark answered</button>
                                    <button v-if="q.status !== 'hidden'" type="button" class="text-red-700" @click="act('hide', q.id)">Hide</button>
                                </div>
                            </article>
                        </div>
                    </div>
                </section>

                <section class="lg:col-span-2" aria-labelledby="chat-heading">
                    <div class="rounded-lg bg-white p-4 shadow">
                        <h3 id="chat-heading" class="text-sm font-semibold text-gray-700">Live chat</h3>
                        <p class="mt-1 text-xs text-gray-600">Posts appear to everyone straight away. Hide anything that shouldn't be there.</p>
                        <p v-if="!chat.length" class="mt-3 text-sm text-gray-600">No messages yet.</p>
                        <ul class="mt-3 max-h-[36rem] divide-y divide-gray-100 overflow-y-auto">
                            <li v-for="m in [...chat].reverse()" :key="m.id" class="flex items-start justify-between gap-3 py-2">
                                <p class="min-w-0 text-sm text-gray-800">
                                    <span class="font-semibold">{{ m.author }}</span>
                                    <span class="ml-1 text-xs text-gray-600">{{ clock(m.created_at) }}</span>
                                    <span class="block break-words">{{ m.body }}</span>
                                </p>
                                <button type="button" class="shrink-0 text-sm text-red-700" @click="hideChat(m)">Hide</button>
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
