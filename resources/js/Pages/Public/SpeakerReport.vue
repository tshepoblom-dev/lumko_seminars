<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/Button.vue';
import { longDate, shortDate } from '@/lib/eventFormat';
import { REACTIONS } from '@/lib/reactions';

// The speaker's report, reached by the emailed link (no login), from the
// speaker portal, or by staff as a preview from Event → Speakers.
const props = defineProps({
    event: { type: Object, required: true },
    speaker: { type: Object, required: true },
    contacts: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    polls: { type: Array, default: () => [] },
    audience: { type: Object, required: true },
    csvUrl: { type: String, default: null },
    preview: { type: Boolean, default: false },
    backUrl: { type: String, default: null }, // set when opened from the speaker portal
});

const number = (n) => Number(n ?? 0).toLocaleString('en-ZA');
const percent = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0);

const reactionList = (counts) =>
    REACTIONS.filter((r) => counts?.[r.type]).map((r) => ({ ...r, count: counts[r.type] }));

const industryTotal = computed(() => props.audience.industries.reduce((sum, i) => sum + i.count, 0));
</script>

<template>
    <Head :title="`Speaker report · ${event.name}`">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <p v-if="preview" class="bg-signal-700 px-4 py-2 text-center text-sm text-white" role="note">
        Staff preview of what {{ speaker.name }} sees. Viewing contact details here is recorded in the audit log.
    </p>

    <header class="stage border-b border-white/10">
        <div class="shell pb-10 pt-10 sm:pt-14">
            <a v-if="backUrl" :href="backUrl" class="link mb-6 inline-block text-sm text-white/75">← Back to your speaker portal</a>
            <p class="eyebrow text-white/60">Speaker report · {{ event.name }}</p>
            <h1 class="display mt-3 text-display-sm text-white">Thank you, {{ speaker.name }}</h1>
            <p class="mt-3 max-w-prose text-[0.9375rem] leading-relaxed text-white/75">
                Here are the attendees who asked to connect with you, and what happened in your session.
                <template v-if="!event.has_ended">The event hasn't ended yet, so this page keeps updating.</template>
            </p>
        </div>
    </header>

    <main id="main" class="bg-paper">
        <div class="shell space-y-14 py-12">
            <!-- 1. Connections -->
            <section aria-labelledby="contacts-title">
                <div class="flex flex-wrap items-end justify-between gap-3 border-b border-ink-900 pb-3">
                    <h2 id="contacts-title" class="text-xl text-ink-900">
                        People who asked to connect
                        <span class="tnum text-ink-500">({{ contacts.length }})</span>
                    </h2>
                    <Button v-if="csvUrl && contacts.length" :href="csvUrl" native variant="secondary" size="sm">Download as CSV</Button>
                </div>
                <p class="mt-3 max-w-prose text-sm text-ink-600">
                    Each of these people chose to share their details with you personally. Nobody was added without asking.
                </p>

                <!-- Phones: one card per person, so the note is never scrolled out of view. -->
                <ul v-if="contacts.length" class="mt-4 divide-y divide-rule border border-rule bg-white sm:hidden">
                    <li v-for="(c, i) in contacts" :key="i" class="space-y-1 p-4 text-sm">
                        <p class="font-medium text-ink-900">{{ c.name }}</p>
                        <p v-if="c.job_title || c.organisation" class="text-meta text-ink-500">{{ [c.job_title, c.organisation].filter(Boolean).join(' · ') }}</p>
                        <p><a :href="`mailto:${c.email}`" class="link break-all">{{ c.email }}</a></p>
                        <p v-if="c.note" class="whitespace-pre-line text-ink-800">“{{ c.note }}”</p>
                        <p class="text-meta text-ink-500">Connected {{ shortDate(c.since) }}</p>
                    </li>
                </ul>

                <div v-if="contacts.length" class="mt-4 hidden overflow-x-auto border border-rule bg-white sm:block">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-ink-900">
                            <tr>
                                <th scope="col" class="eyebrow px-4 py-3 text-left text-ink-500">Name</th>
                                <th scope="col" class="eyebrow px-4 py-3 text-left text-ink-500">Email</th>
                                <th scope="col" class="eyebrow px-4 py-3 text-left text-ink-500">Organisation</th>
                                <th scope="col" class="eyebrow px-4 py-3 text-left text-ink-500">Their note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-rule">
                            <tr v-for="(c, i) in contacts" :key="i" class="align-top">
                                <td class="px-4 py-3">
                                    <span class="font-medium text-ink-900">{{ c.name }}</span>
                                    <span v-if="c.job_title" class="block text-meta text-ink-500">{{ c.job_title }}</span>
                                </td>
                                <td class="px-4 py-3"><a :href="`mailto:${c.email}`" class="link">{{ c.email }}</a></td>
                                <td class="px-4 py-3 text-ink-800">{{ c.organisation || '—' }}</td>
                                <td class="max-w-sm px-4 py-3 text-ink-800">
                                    <span v-if="c.note" class="whitespace-pre-line">{{ c.note }}</span>
                                    <span v-else class="text-ink-500">—</span>
                                    <span class="block text-meta text-ink-500">Connected {{ shortDate(c.since) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="mt-4 border border-rule bg-white p-6 text-sm text-ink-600">
                    No one has asked to connect yet. Attendees can connect from their dashboard and the feedback form
                    <template v-if="!event.has_ended">during and</template> after the event.
                </p>

                <div class="mt-4 max-w-prose border-t-2 border-signal-500 bg-white p-4 text-sm text-ink-800">
                    <p class="font-medium text-ink-900">Using these details</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-ink-700">
                        <li>Contact people about your session and what they asked about.</li>
                        <li>Don't add them to a mailing list or newsletter unless they agree to that separately.</li>
                        <li>If someone asks you to stop or to delete their details, please do.</li>
                        <li>Anyone who withdraws is removed from this page straight away.</li>
                    </ul>
                </div>
            </section>

            <!-- 2. Sessions -->
            <section aria-labelledby="sessions-title">
                <h2 id="sessions-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Your sessions</h2>
                <p v-if="!sessions.length" class="mt-4 text-sm text-ink-600">You weren't named on a session in the programme.</p>
                <article v-for="(s, i) in sessions" :key="i" class="mt-6 border border-rule bg-white p-5">
                    <h3 class="font-sans text-base font-semibold text-ink-900" style="font-stretch: 100%">{{ s.title }}</h3>
                    <p v-if="s.start_time" class="tnum text-meta text-ink-500">{{ s.start_time }}<template v-if="s.end_time">–{{ s.end_time }}</template></p>

                    <dl class="mt-4 grid gap-px border border-rule bg-rule sm:grid-cols-3">
                        <div class="bg-white p-4">
                            <dt class="text-meta text-ink-500">Questions asked</dt>
                            <dd class="mt-1 text-2xl font-semibold text-ink-900">{{ number(s.questions_submitted) }}</dd>
                        </div>
                        <div class="bg-white p-4">
                            <dt class="text-meta text-ink-500">Chat messages</dt>
                            <dd class="mt-1 text-2xl font-semibold text-ink-900">{{ number(s.chat_messages) }}</dd>
                        </div>
                        <div class="bg-white p-4">
                            <dt class="text-meta text-ink-500">Reactions</dt>
                            <dd class="mt-1 flex flex-wrap gap-3 text-sm text-ink-900">
                                <span v-for="r in reactionList(s.reactions)" :key="r.type" :title="r.label">
                                    <span aria-hidden="true">{{ r.emoji }}</span><span class="sr-only">{{ r.label }}:</span>
                                    <span class="tnum"> {{ number(r.count) }}</span>
                                </span>
                                <span v-if="!reactionList(s.reactions).length" class="text-ink-500">None</span>
                            </dd>
                        </div>
                    </dl>

                    <div v-if="s.questions.length" class="mt-5">
                        <h4 class="text-sm font-medium text-ink-900">Questions from the audience</h4>
                        <ol class="mt-2 divide-y divide-rule border-y border-rule">
                            <li v-for="(q, qi) in s.questions" :key="qi" class="flex items-start justify-between gap-4 py-2.5 text-sm">
                                <span class="text-ink-800">{{ q.body }}</span>
                                <span class="tnum shrink-0 text-meta text-ink-500">{{ q.votes }} {{ q.votes === 1 ? 'upvote' : 'upvotes' }}</span>
                            </li>
                        </ol>
                    </div>
                </article>
            </section>

            <!-- 3. Polls -->
            <section v-if="polls.length" aria-labelledby="polls-title">
                <h2 id="polls-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Your polls</h2>
                <article v-for="(p, i) in polls" :key="i" class="mt-6 border border-rule bg-white p-5">
                    <h3 class="font-sans text-base font-semibold text-ink-900" style="font-stretch: 100%">{{ p.question }}</h3>
                    <p class="text-meta text-ink-500">{{ number(p.responses) }} {{ p.responses === 1 ? 'response' : 'responses' }}</p>
                    <ul class="mt-4 space-y-3">
                        <li v-for="(o, oi) in p.options" :key="oi" class="text-sm">
                            <div class="flex justify-between gap-4">
                                <span class="text-ink-800">{{ o.label }}</span>
                                <span class="tnum text-ink-500">{{ number(o.responses) }} · {{ percent(o.responses, p.responses) }}%</span>
                            </div>
                            <div class="mt-1 h-2 bg-signal-50" aria-hidden="true">
                                <div class="h-2 bg-signal-500" :style="{ width: `${percent(o.responses, p.responses)}%` }"></div>
                            </div>
                        </li>
                    </ul>
                </article>
            </section>

            <!-- 4. Audience -->
            <section aria-labelledby="audience-title">
                <h2 id="audience-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">The audience</h2>
                <p class="mt-3 text-sm text-ink-600">Figures for the whole event{{ event.starts_at ? `, ${longDate(event.starts_at)}` : '' }}. No one is identifiable here.</p>
                <dl class="mt-4 grid gap-px border border-rule bg-rule sm:grid-cols-4">
                    <div class="bg-white p-4">
                        <dt class="text-meta text-ink-500">Registered</dt>
                        <dd class="mt-1 text-2xl font-semibold text-ink-900">{{ number(audience.registered) }}</dd>
                        <dd class="text-meta text-ink-500">{{ number(audience.online) }} online · {{ number(audience.in_person) }} in person</dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-meta text-ink-500">Checked in at the venue</dt>
                        <dd class="mt-1 text-2xl font-semibold text-ink-900">{{ number(audience.checked_in) }}</dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-meta text-ink-500">Event rating</dt>
                        <dd class="mt-1 text-2xl font-semibold text-ink-900">
                            <template v-if="audience.feedback_average !== null">{{ audience.feedback_average }} / 5</template>
                            <template v-else>—</template>
                        </dd>
                        <dd class="text-meta text-ink-500">from {{ number(audience.feedback_count) }} {{ audience.feedback_count === 1 ? 'response' : 'responses' }}</dd>
                    </div>
                    <div class="bg-white p-4">
                        <dt class="text-meta text-ink-500">Asked to connect with you</dt>
                        <dd class="mt-1 text-2xl font-semibold text-ink-900">{{ number(contacts.length) }}</dd>
                    </div>
                </dl>

                <div v-if="audience.industries.length" class="mt-6 border border-rule bg-white p-5">
                    <h3 class="text-sm font-medium text-ink-900">Industries</h3>
                    <p class="text-meta text-ink-500">Groups of fewer than 5 people are counted under “Other”.</p>
                    <ul class="mt-4 space-y-3">
                        <li v-for="ind in audience.industries" :key="ind.label" class="text-sm">
                            <div class="flex justify-between gap-4">
                                <span class="text-ink-800">{{ ind.label }}</span>
                                <span class="tnum text-ink-500">{{ number(ind.count) }} · {{ percent(ind.count, industryTotal) }}%</span>
                            </div>
                            <div class="mt-1 h-2 bg-signal-50" aria-hidden="true">
                                <div class="h-2 bg-signal-500" :style="{ width: `${percent(ind.count, industryTotal)}%` }"></div>
                            </div>
                        </li>
                    </ul>
                </div>
                <p class="mt-4 text-meta text-ink-500">
                    The platform doesn't record attendance or ratings per session, so those aren't shown.
                </p>
            </section>
        </div>
    </main>
</template>
