<script setup>
import { onBeforeUnmount, ref } from 'vue';

// Emoji drifting up the right side of the stream. The page calls burst()
// for its own clicks straight away, and for everyone else's when the
// aggregate counts go up. Decorative: the buttons are what's accessible.
// With reduced motion there's no drift — a brief count pill instead.

const MAX_ON_SCREEN = 40;
const LIFETIME_MS = 2600;

const floating = ref([]); // { id, emoji, left, drift, delay, scale }
const pills = ref([]); // reduced motion: { id, emoji, count }
let nextId = 1;
const timers = new Set();

const reducedMotion = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

function later(fn, ms) {
    const t = setTimeout(() => { timers.delete(t); fn(); }, ms);
    timers.add(t);
}

function burst(emoji, count = 1) {
    if (!emoji || count < 1) return;

    if (reducedMotion()) {
        const id = nextId++;
        pills.value = [...pills.value.slice(-3), { id, emoji, count }];
        later(() => { pills.value = pills.value.filter((p) => p.id !== id); }, 1800);
        return;
    }

    const room = Math.max(0, MAX_ON_SCREEN - floating.value.length);
    const items = Array.from({ length: Math.min(count, room) }, (_, i) => ({
        id: nextId++,
        emoji,
        left: 10 + Math.random() * 60, // % across the lane
        drift: Math.round((Math.random() - 0.5) * 40), // px sideways by the top
        delay: i * 140,
        scale: 0.9 + Math.random() * 0.5,
    }));
    floating.value = [...floating.value, ...items];

    const ids = new Set(items.map((item) => item.id));
    later(() => { floating.value = floating.value.filter((f) => !ids.has(f.id)); }, LIFETIME_MS + items.length * 140);
}

onBeforeUnmount(() => timers.forEach(clearTimeout));

defineExpose({ burst });
</script>

<template>
    <div class="reaction-lane pointer-events-none absolute bottom-16 right-2 top-4 z-20 w-24 overflow-hidden" aria-hidden="true">
        <span
            v-for="f in floating"
            :key="f.id"
            class="floating-emoji absolute bottom-0 select-none text-3xl"
            :style="{ left: f.left + '%', '--drift': f.drift + 'px', '--scale': f.scale, animationDelay: f.delay + 'ms' }"
        >{{ f.emoji }}</span>
        <div class="absolute bottom-0 right-0 flex flex-col items-end gap-1">
            <span v-for="p in pills" :key="p.id" class="rounded-full bg-black/70 px-2 py-0.5 text-sm text-white">
                {{ p.emoji }}<span v-if="p.count > 1" class="tnum"> ×{{ p.count }}</span>
            </span>
        </div>
    </div>
</template>

<style scoped>
/* Container units let the drift cover the lane's height at any stage size. */
.reaction-lane {
    container-type: size;
}

.floating-emoji {
    opacity: 0;
    animation: float-up 2600ms cubic-bezier(0.22, 0.61, 0.36, 1) forwards;
    will-change: transform, opacity;
}

@keyframes float-up {
    0% { opacity: 0; transform: translate3d(0, 0, 0) scale(calc(var(--scale) * 0.6)); }
    12% { opacity: 1; transform: translate3d(0, -8cqh, 0) scale(var(--scale)); }
    75% { opacity: 1; }
    100% { opacity: 0; transform: translate3d(var(--drift), -85cqh, 0) scale(var(--scale)); }
}
</style>
