<script setup>
import { nextTick, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: null },
    maxWidth: { type: String, default: 'md' }, // sm | md | lg
});

const emit = defineEmits(['close']);

const panelRef = ref(null);
let previouslyFocused = null;

const maxWidths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl' };

function close() {
    emit('close');
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        close();
        return;
    }

    if (event.key !== 'Tab' || !panelRef.value) return;

    const focusable = panelRef.value.querySelectorAll(
        'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
    );
    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(() => props.show, async (isShown) => {
    if (isShown) {
        previouslyFocused = document.activeElement;
        await nextTick();
        panelRef.value?.querySelector('a[href], button, textarea, input, select, [tabindex]')?.focus();
    } else {
        previouslyFocused?.focus?.();
    }
});
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown="onKeydown">
            <div class="fixed inset-0 bg-ink-950/60" aria-hidden="true" @click="close" />

            <div
                ref="panelRef"
                role="dialog"
                aria-modal="true"
                :aria-label="title ?? undefined"
                :class="['relative w-full border-t-4 border-ink-900 bg-white p-6 shadow-float', maxWidths[maxWidth] ?? maxWidths.md]"
            >
                <div v-if="title || $slots.header" class="mb-5 flex items-center justify-between gap-4">
                    <h2 v-if="title" class="font-display text-xl text-ink-900">{{ title }}</h2>
                    <slot name="header" />
                    <button
                        type="button"
                        class="flex h-9 w-9 items-center justify-center rounded-ctl text-ink-500 hover:bg-ink-50 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500"
                        aria-label="Close"
                        @click="close"
                    >
                        ✕
                    </button>
                </div>

                <slot />

                <div v-if="$slots.footer" class="mt-6 flex justify-end gap-2">
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
