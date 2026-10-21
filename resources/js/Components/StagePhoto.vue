<script setup>
import { ref } from 'vue';

// A photograph set on the navy stage. If the file is missing or fails to
// load, the slot keeps its shape and shows the fallback slot instead of a
// broken image, so a page without photography still looks finished.
defineProps({
    src: { type: String, default: null },
    alt: { type: String, default: '' },
    tone: { type: Boolean, default: true }, // subtle navy grade over the photo
    eager: { type: Boolean, default: false },
});

const failed = ref(false);
const loaded = ref(false);
</script>

<template>
    <div class="relative overflow-hidden bg-ink-800">
        <img
            v-if="src && !failed"
            :src="src"
            :alt="alt"
            :loading="eager ? 'eager' : 'lazy'"
            decoding="async"
            class="absolute inset-0 h-full w-full object-cover transition-opacity duration-700"
            :class="loaded ? 'opacity-100' : 'opacity-0'"
            @load="loaded = true"
            @error="failed = true"
        />
        <div
            v-if="src && !failed && tone"
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 bg-ink-900/15 mix-blend-multiply"
        ></div>
        <div v-if="!src || failed || !loaded" class="absolute inset-0">
            <slot name="fallback" />
        </div>
    </div>
</template>
