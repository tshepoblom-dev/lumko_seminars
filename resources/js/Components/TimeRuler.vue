<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { minutes } from '@/lib/eventFormat';

// The series signature: the morning drawn to scale. Each session's width is
// its duration, so an 80-minute breakfast reads at a glance as a 25-minute
// keynote, a panel, a close. Titles live in the list beside it, matched by
// the index numbers under each segment.
const props = defineProps({
    sessions: { type: Array, required: true }, // [{ start_time: 'HH:MM', end_time: 'HH:MM', title }]
    size: { type: String, default: 'full' }, // full | compact
    onStage: { type: Boolean, default: false },
    nowMinutes: { type: Number, default: null }, // highlight the running session
    label: { type: String, default: 'Programme timeline' },
    // When the same schedule is already listed next to the ruler, hide the
    // ruler from assistive tech instead of reading the programme twice.
    decorative: { type: Boolean, default: false },
});

const spans = computed(() => props.sessions
    .map((s, i) => ({ ...s, index: i + 1, a: minutes(s.start_time), b: minutes(s.end_time) }))
    .filter((s) => s.a !== null && s.b !== null && s.b > s.a));

const start = computed(() => Math.min(...spans.value.map((s) => s.a)));
const end = computed(() => Math.max(...spans.value.map((s) => s.b)));
const total = computed(() => Math.max(1, end.value - start.value));

const pct = (m) => ((m - start.value) / total.value) * 100;

const segments = computed(() => spans.value.map((s) => ({
    ...s,
    left: pct(s.a),
    width: ((s.b - s.a) / total.value) * 100,
    running: props.nowMinutes !== null && props.nowMinutes >= s.a && props.nowMinutes < s.b,
})));

// Ten-minute ticks on the full ruler; the hour and the ends are labelled.
const ticks = computed(() => {
    const out = [];
    const first = Math.ceil(start.value / 10) * 10;
    for (let m = first; m <= end.value; m += 10) {
        out.push({ m, left: pct(m), major: m % 30 === 0 || m === start.value || m === end.value });
    }
    return out;
});

const hhmm = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

const nowLeft = computed(() => (props.nowMinutes !== null && props.nowMinutes >= start.value && props.nowMinutes <= end.value
    ? pct(props.nowMinutes) : null));

// Draw once, when the ruler first scrolls into view.
const root = ref(null);
const drawn = ref(false);
let observer;

onMounted(() => {
    const reduced = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reduced || typeof IntersectionObserver === 'undefined') {
        drawn.value = true;
        return;
    }
    observer = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) {
            drawn.value = true;
            observer.disconnect();
        }
    }, { threshold: 0.3 });
    observer.observe(root.value);
});

onBeforeUnmount(() => observer?.disconnect());

const tone = computed(() => (props.onStage
    ? { bar: 'bg-white', running: 'bg-signal-400', tick: 'bg-white/35', text: 'text-white/60', strong: 'text-white' }
    : { bar: 'bg-ink-900', running: 'bg-signal-500', tick: 'bg-ink-200', text: 'text-ink-400', strong: 'text-ink-900' }));
</script>

<template>
    <figure v-if="segments.length" ref="root" :aria-label="decorative ? undefined : label" :aria-hidden="decorative ? 'true' : undefined" class="w-full">
        <!-- Scale -->
        <div v-if="size === 'full'" class="relative h-6" aria-hidden="true">
            <template v-for="t in ticks" :key="t.m">
                <span
                    class="absolute bottom-0 w-px"
                    :class="[tone.tick, t.major ? 'h-2.5' : 'h-1.5']"
                    :style="{ left: `${t.left}%` }"
                ></span>
                <span
                    v-if="t.major"
                    class="tnum absolute bottom-3 text-[0.6875rem] font-medium"
                    :class="[t.m === start || t.m === end ? tone.strong : tone.text, t.m === end ? '-translate-x-full' : t.m === start ? '' : '-translate-x-1/2', t.m % 60 !== 0 && t.m !== start && t.m !== end ? 'hidden sm:inline' : '']"
                    :style="{ left: `${t.left}%` }"
                >{{ hhmm(t.m) }}</span>
            </template>
        </div>

        <!-- Bar -->
        <div class="relative" :class="size === 'full' ? 'mt-1.5 h-3 sm:h-4' : 'h-2'" aria-hidden="true">
            <span
                v-for="(s, i) in segments"
                :key="s.index"
                class="absolute inset-y-0 origin-left transition-transform duration-700 ease-out"
                :class="[s.running ? tone.running : tone.bar, drawn ? 'scale-x-100' : 'scale-x-0']"
                :style="{
                    left: `${s.left}%`,
                    width: `calc(${s.width}% - 2px)`,
                    transitionDelay: `${i * 90}ms`,
                }"
            ></span>
            <span
                v-if="nowLeft !== null"
                class="absolute -inset-y-1.5 w-0.5 bg-signal-500"
                :style="{ left: `${nowLeft}%` }"
            ></span>
        </div>

        <!-- Index marks under each segment -->
        <div v-if="size === 'full'" class="relative mt-2 h-4" aria-hidden="true">
            <span
                v-for="s in segments"
                :key="s.index"
                class="tnum absolute text-[0.6875rem] font-semibold"
                :class="[s.running ? 'text-signal-500' : tone.strong, s.width < 5 ? 'hidden sm:inline' : '', s.width < 3 ? 'sm:hidden' : '']"
                :style="{ left: `${s.left}%` }"
            >{{ String(s.index).padStart(2, '0') }}</span>
        </div>

        <!-- Text alternative: the same schedule as a list. -->
        <figcaption v-if="!decorative" class="sr-only">
            <ol>
                <li v-for="s in segments" :key="s.index">{{ s.start_time }} to {{ s.end_time }}: {{ s.title }}</li>
            </ol>
        </figcaption>
    </figure>
</template>
