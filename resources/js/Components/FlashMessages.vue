<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const dismissed = ref({});

const messages = computed(() => {
    const flash = page.props.flash ?? {};

    return ['success', 'info', 'error']
        .filter((type) => flash[type])
        .map((type) => ({ type, text: flash[type], key: `${type}:${flash[type]}` }))
        .filter((message) => !dismissed.value[message.key]);
});

// A new page visit with new flash data should be shown again.
watch(
    () => page.props.flash,
    () => {
        dismissed.value = {};
    },
);

const styles = {
    success: 'border-t-verified-500',
    info: 'border-t-signal-500',
    error: 'border-t-red-700',
};
</script>

<template>
    <!-- Polite live region so screen readers announce flash messages. -->
    <div aria-live="polite" class="space-y-2 pt-4 empty:pt-0">
        <div
            v-for="message in messages"
            :key="message.key"
            :role="message.type === 'error' ? 'alert' : 'status'"
            :class="['flex items-start justify-between gap-4 border border-rule border-t-2 bg-white px-4 py-3 text-sm text-ink-900', styles[message.type]]"
        >
            <p>{{ message.text }}</p>
            <button
                type="button"
                class="shrink-0 text-meta font-medium text-ink-500 underline underline-offset-4 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500 focus-visible:ring-offset-2"
                @click="dismissed[message.key] = true"
            >
                Dismiss
            </button>
        </div>
    </div>
</template>
