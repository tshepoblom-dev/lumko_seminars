<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DailyColumnChart from '@/Components/Admin/DailyColumnChart.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    days: { type: Number, required: true },
    ranges: { type: Array, required: true },
    today: { type: Object, required: true },
    history: { type: Object, required: true },
    live: { type: Object, required: true },
    liveWindowSeconds: { type: Number, required: true },
});

const number = (n) => Number(n ?? 0).toLocaleString('en-ZA');

function delta(now, before) {
    const diff = now - before;
    if (diff === 0) return 'Same as yesterday';
    return `${diff > 0 ? '+' : '−'}${number(Math.abs(diff))} on yesterday`;
}

const visitorPoints = computed(() => props.history.daily.map((d) => ({ date: d.date, value: d.visitors })));
const hitPoints = computed(() => props.history.daily.map((d) => ({ date: d.date, value: d.hits })));
const rangeHits = computed(() => props.history.daily.reduce((sum, d) => sum + d.hits, 0));
// One decimal place, so a quiet month doesn't read as "0 a day".
const averageVisitors = computed(() => {
    const days = props.history.daily.length;
    const average = days ? props.history.daily.reduce((sum, d) => sum + d.visitors, 0) / days : 0;
    if (average > 0 && average < 0.05) return 'under 0.1';
    return average.toLocaleString('en-ZA', { maximumFractionDigits: 1 });
});

// Range change: the charts keep their frame at reduced opacity while loading.
const loading = ref(false);
function setRange(days) {
    if (days === props.days) return;
    router.get(route('admin.analytics.index'), { days }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

const dayFormat = new Intl.DateTimeFormat('en-ZA', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
const longDate = (iso) => dayFormat.format(new Date(`${iso}T00:00:00Z`));

// "Live now" and today's figures refresh every 15 seconds while the tab is visible.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        router.reload({ only: ['live', 'today'] });
    }, 15000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Analytics</h2>
        </template>

        <Head title="Analytics" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">
                <!-- Live now -->
                <section aria-labelledby="live-heading">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 id="live-heading" class="font-display text-xl text-ink-900">Live now</h3>
                        <p class="text-meta text-ink-500">Updates every 15 seconds</p>
                    </div>
                    <div class="mt-3 grid gap-px border border-rule bg-rule sm:grid-cols-3">
                        <div class="bg-white p-5">
                            <p class="text-sm text-ink-500">Online on the site</p>
                            <p class="mt-1 text-4xl font-semibold text-ink-900" data-testid="live-total">{{ number(live.total) }}</p>
                            <p class="mt-1 text-meta text-ink-500">{{ number(live.signed_in) }} signed in</p>
                        </div>
                        <div class="bg-white p-5">
                            <p class="text-sm text-ink-500">Watching the livestream</p>
                            <p class="mt-1 text-4xl font-semibold text-ink-900" data-testid="live-watching">{{ number(live.watching_total) }}</p>
                            <ul v-if="live.watching.length" class="mt-1 space-y-0.5 text-meta text-ink-500">
                                <li v-for="w in live.watching" :key="w.event_id">{{ w.event }}: {{ number(w.viewers) }}</li>
                            </ul>
                            <p v-else class="mt-1 text-meta text-ink-500">No one on a Live page</p>
                        </div>
                        <div class="bg-white p-5">
                            <p class="text-sm text-ink-500">Pages open now</p>
                            <ul v-if="live.pages.length" class="mt-2 space-y-1 text-sm">
                                <li v-for="p in live.pages" :key="p.path" class="flex justify-between gap-3">
                                    <span class="min-w-0 truncate text-ink-800">{{ p.path }}</span>
                                    <span class="tabular-nums text-ink-500">{{ number(p.sessions) }}</span>
                                </li>
                            </ul>
                            <p v-else class="mt-2 text-sm text-ink-500">No one is on the site right now.</p>
                        </div>
                    </div>
                </section>

                <!-- Today -->
                <section aria-labelledby="today-heading">
                    <h3 id="today-heading" class="font-display text-xl text-ink-900">Today</h3>
                    <div class="mt-3 grid gap-px border border-rule bg-rule sm:grid-cols-2">
                        <div class="bg-white p-5">
                            <p class="text-sm text-ink-500">Unique visitors</p>
                            <p class="mt-1 text-4xl font-semibold text-ink-900" data-testid="today-visitors">{{ number(today.visitors) }}</p>
                            <p class="mt-1 text-meta text-ink-500">{{ delta(today.visitors, today.visitors_yesterday) }}</p>
                        </div>
                        <div class="bg-white p-5">
                            <p class="text-sm text-ink-500">Page hits</p>
                            <p class="mt-1 text-4xl font-semibold text-ink-900" data-testid="today-hits">{{ number(today.hits) }}</p>
                            <p class="mt-1 text-meta text-ink-500">{{ delta(today.hits, today.hits_yesterday) }}</p>
                        </div>
                    </div>
                </section>

                <!-- History -->
                <section aria-labelledby="history-heading" class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 id="history-heading" class="font-display text-xl text-ink-900">Over time</h3>
                        <div role="group" aria-label="Date range" class="inline-flex border border-rule-strong bg-white">
                            <button
                                v-for="r in ranges"
                                :key="r"
                                type="button"
                                :aria-pressed="r === days"
                                :class="[
                                    'min-h-9 px-3.5 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500',
                                    r === days ? 'bg-ink-900 text-white' : 'text-ink-800 hover:bg-paper',
                                ]"
                                @click="setRange(r)"
                            >
                                Last {{ r }} days
                            </button>
                        </div>
                    </div>

                    <div :class="['grid gap-4 transition-opacity lg:grid-cols-2', loading ? 'opacity-50' : '']">
                        <div class="border border-rule bg-white p-5">
                            <h4 class="text-sm font-medium text-ink-900">Unique visitors per day</h4>
                            <p class="text-meta text-ink-500">Average {{ averageVisitors }} a day</p>
                            <div class="mt-3">
                                <DailyColumnChart :points="visitorPoints" label="Unique visitors per day" unit="visitors" unit-one="visitor" />
                            </div>
                        </div>
                        <div class="border border-rule bg-white p-5">
                            <h4 class="text-sm font-medium text-ink-900">Page hits per day</h4>
                            <p class="text-meta text-ink-500">{{ number(rangeHits) }} in the last {{ days }} days</p>
                            <div class="mt-3">
                                <DailyColumnChart :points="hitPoints" label="Page hits per day" unit="hits" unit-one="hit" />
                            </div>
                        </div>
                    </div>

                    <details class="border border-rule bg-white">
                        <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-ink-800 hover:bg-paper">Show as a table</summary>
                        <div class="max-h-96 overflow-y-auto border-t border-rule">
                            <table class="min-w-full text-sm">
                                <thead class="sticky top-0 border-b border-ink-900 bg-white">
                                    <tr>
                                        <th scope="col" class="eyebrow px-5 py-2 text-left text-ink-500">Day</th>
                                        <th scope="col" class="eyebrow px-5 py-2 text-right text-ink-500">Visitors</th>
                                        <th scope="col" class="eyebrow px-5 py-2 text-right text-ink-500">Hits</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-rule">
                                    <tr v-for="d in [...history.daily].reverse()" :key="d.date">
                                        <td class="px-5 py-2 text-ink-800">{{ longDate(d.date) }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums">{{ number(d.visitors) }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums">{{ number(d.hits) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </details>

                    <div :class="['grid gap-4 lg:grid-cols-2', loading ? 'opacity-50' : '']">
                        <div class="border border-rule bg-white">
                            <h4 class="border-b border-rule px-5 py-3 text-sm font-medium text-ink-900">Top pages</h4>
                            <table v-if="history.topPages.length" class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-rule">
                                        <th scope="col" class="px-5 py-2 text-left text-meta font-medium text-ink-500">Page</th>
                                        <th scope="col" class="px-5 py-2 text-right text-meta font-medium text-ink-500">Hits</th>
                                        <th scope="col" class="px-5 py-2 text-right text-meta font-medium text-ink-500">Visitors</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-rule">
                                    <tr v-for="p in history.topPages" :key="p.path">
                                        <td class="max-w-[16rem] truncate px-5 py-2 text-ink-800">{{ p.path }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums">{{ number(p.hits) }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums">{{ number(p.visitors) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="px-5 py-6 text-sm text-ink-500">No page hits in this range yet.</p>
                        </div>
                        <div class="border border-rule bg-white">
                            <h4 class="border-b border-rule px-5 py-3 text-sm font-medium text-ink-900">Where visitors came from</h4>
                            <table v-if="history.topReferrers.length" class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b border-rule">
                                        <th scope="col" class="px-5 py-2 text-left text-meta font-medium text-ink-500">Site</th>
                                        <th scope="col" class="px-5 py-2 text-right text-meta font-medium text-ink-500">Hits</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-rule">
                                    <tr v-for="r in history.topReferrers" :key="r.host">
                                        <td class="px-5 py-2 text-ink-800">{{ r.host }}</td>
                                        <td class="px-5 py-2 text-right tabular-nums">{{ number(r.hits) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p v-else class="px-5 py-6 text-sm text-ink-500">No referring sites in this range. Direct visits and links from email apps don't send one.</p>
                        </div>
                    </div>
                </section>

                <section aria-labelledby="method-heading" class="max-w-3xl text-meta text-ink-500">
                    <h3 id="method-heading" class="font-medium text-ink-800">How this is counted</h3>
                    <p class="mt-1">
                        A page hit is each page an attendee or visitor opens. A unique visitor is counted once per day, from an
                        anonymous code that changes every midnight, so the same person on two days counts twice and on two
                        devices counts twice. No cookies are set and no IP addresses are stored. Staff, the admin area and
                        search-engine bots are left out. "Online" means the page checked in within the last
                        {{ liveWindowSeconds }} seconds.
                    </p>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
