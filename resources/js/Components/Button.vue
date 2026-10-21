<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    href: { type: String, default: null },
    variant: { type: String, default: 'primary' }, // primary | secondary | danger | outline | inverse | ghost
    size: { type: String, default: 'md' }, // sm | md | lg
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    arrow: { type: Boolean, default: false },
    external: { type: Boolean, default: false }, // new tab
    native: { type: Boolean, default: false }, // plain link, same tab, no Inertia visit (downloads)
});

// Square-shouldered controls: 2px corners, no shadow. Primary is ink on
// paper; `inverse` is the white-on-stage equivalent.
const variants = {
    primary: 'bg-ink-900 text-white hover:bg-ink-700 disabled:bg-ink-300',
    secondary: 'border border-rule-strong bg-white text-ink-800 hover:border-ink-900 disabled:text-ink-300',
    danger: 'bg-red-700 text-white hover:bg-red-800 disabled:bg-red-300',
    outline: 'border border-ink-900 text-ink-900 hover:bg-ink-900 hover:text-white disabled:border-rule disabled:text-ink-300',
    inverse: 'bg-white text-ink-900 hover:bg-signal-50 disabled:bg-white/40',
    ghost: 'border border-white/35 text-white hover:border-white disabled:text-white/40',
};

const sizes = {
    sm: 'min-h-9 px-3.5 text-sm',
    md: 'min-h-11 px-5 text-sm',
    lg: 'min-h-12 px-6 text-[0.9375rem]',
};

const classes = computed(() => [
    'group inline-flex items-center justify-center gap-2.5 rounded-ctl font-medium transition-colors duration-150',
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500 focus-visible:ring-offset-2',
    'disabled:cursor-not-allowed',
    variants[props.variant] ?? variants.primary,
    sizes[props.size] ?? sizes.md,
]);
</script>

<template>
    <a v-if="href && (external || native)" :href="href" :target="external ? '_blank' : undefined" :rel="external ? 'noopener' : undefined" :class="classes">
        <slot />
        <span v-if="arrow" aria-hidden="true" class="transition-transform duration-150 ease-out group-hover:translate-x-0.5">&rarr;</span>
    </a>
    <Link v-else-if="href" :href="href" :class="classes">
        <slot />
        <span v-if="arrow" aria-hidden="true" class="transition-transform duration-150 ease-out group-hover:translate-x-0.5">&rarr;</span>
    </Link>
    <button v-else :type="type" :disabled="disabled" :class="classes">
        <slot />
        <span v-if="arrow" aria-hidden="true" class="transition-transform duration-150 ease-out group-hover:translate-x-0.5">&rarr;</span>
    </button>
</template>
