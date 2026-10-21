<script setup>
import { ref } from 'vue';

// One remittance line: label, value, and a copy button. Copying falls back
// silently when the clipboard is unavailable (insecure origin, old browser);
// the value stays selectable either way.
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    emphasis: { type: Boolean, default: false },
    note: { type: String, default: null },
});

const copied = ref(false);
const failed = ref(false);
let timer;

async function copy() {
    failed.value = false;
    try {
        await navigator.clipboard.writeText(String(props.value));
        copied.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        failed.value = true;
    }
}
</script>

<template>
    <div class="py-3">
        <dt class="eyebrow text-ink-400">{{ label }}</dt>
        <dd class="mt-1 flex items-center justify-between gap-4">
            <span class="min-w-0">
                <span
                    class="block break-all tnum text-ink-900"
                    :class="emphasis ? 'font-display text-2xl tracking-wide sm:text-[1.75rem]' : 'text-[0.9375rem] font-medium'"
                    :style="emphasis ? 'font-stretch: 90%; font-weight: 700' : ''"
                >{{ value }}</span>
                <span v-if="note" class="mt-1 block text-meta text-ink-500">{{ note }}</span>
            </span>
            <button
                type="button"
                class="no-print inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-ctl border px-3 text-meta font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500 focus-visible:ring-offset-2"
                :class="copied ? 'border-ink-900 bg-ink-900 text-white' : 'border-rule-strong bg-white text-ink-800 hover:border-ink-900'"
                :aria-label="'Copy ' + label.toLowerCase()"
                @click="copy"
            >
                <svg v-if="!copied" aria-hidden="true" viewBox="0 0 16 16" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="5.25" y="5.25" width="8" height="8" rx="0.5" />
                    <path d="M10.75 5.25V3.25a.5.5 0 0 0-.5-.5h-7a.5.5 0 0 0-.5.5v7a.5.5 0 0 0 .5.5h2" />
                </svg>
                <svg v-else aria-hidden="true" viewBox="0 0 16 16" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.75">
                    <path d="M3 8.5l3 3 7-7" />
                </svg>
                <span aria-hidden="true">{{ copied ? 'Copied' : 'Copy' }}</span>
            </button>
            <span class="sr-only" aria-live="polite">{{ copied ? label + ' copied' : failed ? 'Copy not available, select the text instead' : '' }}</span>
        </dd>
    </div>
</template>
