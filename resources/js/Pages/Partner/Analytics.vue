<script setup>
import DailyColumnChart from '@/Components/Admin/DailyColumnChart.vue';
import PortalPage from '@/Components/PortalPage.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    partner: Object,
    event: Object,
    tier: { type: String, default: null },
    features: { type: Array, default: () => [] },
    totals: Object, // { page_views, page_visitors, leads, resource_opens }
    daily: Array, // [{ date, views, leads }]
    audience: Object, // SpeakerReport::audience()
    minGroup: Number,
});

const number = (n) => Number(n ?? 0).toLocaleString('en-ZA');
const viewPoints = computed(() => props.daily.map((d) => ({ date: d.date, value: d.views })));
const leadPoints = computed(() => props.daily.map((d) => ({ date: d.date, value: d.leads })));
const industryTotal = computed(() => props.audience.industries.reduce((s, i) => s + i.count, 0));
const percent = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0);
</script>

<template>
    <PartnerLayout :partner="partner" :event="event" :features="features">
        <Head :title="`Analytics · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Analytics" width="wide">
            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                Totals only, never individuals. Visits are counted without cookies; groups smaller than {{ minGroup }} people are folded into “Other”.
            </p>

            <dl class="mt-8 grid grid-cols-2 gap-px border border-rule bg-rule lg:grid-cols-4">
                <div class="bg-white p-5">
                    <dt class="text-meta text-ink-500">Partner page visits</dt>
                    <dd class="tnum mt-1 font-display text-3xl text-ink-900">{{ number(totals.page_views) }}</dd>
                </div>
                <div class="bg-white p-5">
                    <dt class="text-meta text-ink-500">Daily visitors (summed)</dt>
                    <dd class="tnum mt-1 font-display text-3xl text-ink-900">{{ number(totals.page_visitors) }}</dd>
                </div>
                <div class="bg-white p-5">
                    <dt class="text-meta text-ink-500">Leads</dt>
                    <dd class="tnum mt-1 font-display text-3xl text-ink-900">{{ number(totals.leads) }}</dd>
                </div>
                <div class="bg-white p-5">
                    <dt class="text-meta text-ink-500">Resource opens</dt>
                    <dd class="tnum mt-1 font-display text-3xl text-ink-900">{{ number(totals.resource_opens) }}</dd>
                </div>
            </dl>

            <div class="mt-8 grid gap-4 lg:grid-cols-2">
                <div class="border border-rule bg-white p-5">
                    <h2 class="text-sm font-medium text-ink-900">Partner page visits per day</h2>
                    <p class="text-meta text-ink-500">Last 30 days</p>
                    <div class="mt-3"><DailyColumnChart :points="viewPoints" label="Partner page visits per day" unit="visits" unit-one="visit" /></div>
                </div>
                <div class="border border-rule bg-white p-5">
                    <h2 class="text-sm font-medium text-ink-900">New leads per day</h2>
                    <p class="text-meta text-ink-500">Last 30 days</p>
                    <div class="mt-3"><DailyColumnChart :points="leadPoints" label="New leads per day" unit="leads" unit-one="lead" /></div>
                </div>
            </div>

            <section class="mt-12" aria-labelledby="audience-title">
                <h2 id="audience-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">The event's audience</h2>
                <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-ink-500">Registered</dt><dd class="tnum text-lg text-ink-900">{{ number(audience.registered) }}</dd></div>
                    <div><dt class="text-ink-500">Online</dt><dd class="tnum text-lg text-ink-900">{{ number(audience.online) }}</dd></div>
                    <div><dt class="text-ink-500">In person</dt><dd class="tnum text-lg text-ink-900">{{ number(audience.in_person) }}</dd></div>
                    <div><dt class="text-ink-500">Checked in</dt><dd class="tnum text-lg text-ink-900">{{ number(audience.checked_in) }}</dd></div>
                </dl>
                <h3 class="mt-8 text-sm font-medium text-ink-900">By industry</h3>
                <ul v-if="audience.industries.length" class="mt-3 max-w-2xl space-y-2">
                    <li v-for="i in audience.industries" :key="i.label" class="grid grid-cols-[1fr_auto] items-center gap-x-4 gap-y-1 text-sm">
                        <span class="text-ink-800">{{ i.label }}</span>
                        <span class="tnum text-ink-600">{{ i.count }} · {{ percent(i.count, industryTotal) }}%</span>
                        <span class="col-span-2 h-1.5 bg-rule" aria-hidden="true">
                            <span class="block h-full bg-signal-500" :style="{ width: percent(i.count, industryTotal) + '%' }"></span>
                        </span>
                    </li>
                </ul>
                <p v-else class="mt-3 text-sm text-ink-500">Not enough registrations yet.</p>
            </section>
        </PortalPage>
    </PartnerLayout>
</template>
