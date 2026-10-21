<script setup>
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

// Replaces Inertia's built-in progress bar, whose bundled template hard-codes
// an invalid ARIA role ("bar") that can't be configured away. This one is
// purely decorative (aria-hidden): a page visit is already announced by the
// browser/screen reader through the new page title and content.
const SHOW_AFTER_MS = 250;

const visible = ref(false);
const width = ref(0);

let showTimer = null;
let trickleTimer = null;
let hideTimer = null;
let loading = false;

function start(event) {
    // Background visits (polling reloads) opt out of progress, as with Inertia's own bar.
    if (event.detail?.visit?.showProgress === false || loading) return;

    loading = true;
    clearTimeout(hideTimer);
    showTimer = setTimeout(() => {
        width.value = 8;
        visible.value = true;
        trickleTimer = setInterval(() => {
            width.value += (95 - width.value) * 0.1;
        }, 200);
    }, SHOW_AFTER_MS);
}

function finish() {
    if (!loading) return;

    loading = false;
    clearTimeout(showTimer);
    clearInterval(trickleTimer);

    if (visible.value) {
        width.value = 100;
        hideTimer = setTimeout(() => {
            visible.value = false;
            width.value = 0;
        }, 250);
    }
}

const removeStart = router.on('start', start);
const removeFinish = router.on('finish', finish);

onBeforeUnmount(() => {
    removeStart();
    removeFinish();
    clearTimeout(showTimer);
    clearTimeout(hideTimer);
    clearInterval(trickleTimer);
});
</script>

<template>
    <div aria-hidden="true" class="pointer-events-none fixed inset-x-0 top-0 z-[9999] h-0.5">
        <div
            v-show="visible"
            data-navigation-progress
            class="h-full bg-signal-500 transition-[width] duration-200 ease-linear motion-reduce:transition-none"
            :style="{ width: `${width}%` }"
        ></div>
    </div>
</template>
