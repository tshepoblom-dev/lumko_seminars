<script setup>
import { computed } from 'vue';

// A radio group drawn as tiles: an icon (or a logo) and a label, on the
// form's `.choice` style (checked and focus states come from there).
// Options come in groups, each with an optional sub-heading.
const props = defineProps({
    id: { type: String, required: true },
    legend: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    modelValue: { type: String, default: '' },
    // [{ heading?, options: [{ value, label, icon?, logoUrl?, logoAlt? }] }]
    groups: { type: Array, required: true },
});

const emit = defineEmits(['update:modelValue']);

const value = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const describedBy = computed(() => [props.hint ? `${props.id}-hint` : null, props.error ? `${props.id}-error` : null].filter(Boolean).join(' ') || undefined);

const initials = (label) => label.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

// Small line icons, decorative: the label says what the option is.
const ICONS = {
    none: 'M6 12h12',
    linkedin: null, // drawn as an "in" monogram below
    lumko_email: 'M4 6h16v12H4z M4 7l8 6 8-6',
    colleague: 'M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6z M3 19c0-3 3-5 6-5s6 2 6 5 M16 11a2.5 2.5 0 1 0 0-5 M17 14c2 .4 4 2 4 5',
    search: 'M10.5 17a6.5 6.5 0 1 0 0-13 6.5 6.5 0 0 0 0 13z M15.5 15.5 20 20',
    lumko_website: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z M3 12h18 M12 3c2.5 2.5 3.5 5.5 3.5 9s-1 6.5-3.5 9c-2.5-2.5-3.5-5.5-3.5-9s1-6.5 3.5-9z',
    other: 'M6 12h.01 M12 12h.01 M18 12h.01',
};
</script>

<template>
    <fieldset :aria-describedby="describedBy">
        <legend class="field-label mb-1.5">{{ legend }}</legend>
        <p v-if="hint" :id="`${id}-hint`" class="field-hint mb-3">{{ hint }}</p>

        <div v-for="(group, gi) in groups" :key="gi" :class="gi > 0 ? 'mt-5' : ''">
            <p v-if="group.heading" class="eyebrow mb-2 text-ink-500" aria-hidden="true">{{ group.heading }}</p>
            <div class="grid grid-cols-1 gap-2 min-[400px]:grid-cols-2 lg:grid-cols-3">
                <label v-for="option in group.options" :key="option.value" class="choice items-center text-sm">
                    <input
                        v-model="value"
                        type="radio"
                        :name="id"
                        :value="option.value"
                        :aria-label="group.heading ? `${option.label} (${group.heading.toLowerCase()})` : undefined"
                    />
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden border border-rule bg-white" aria-hidden="true">
                        <img v-if="option.logoUrl" :src="option.logoUrl" alt="" class="max-h-8 max-w-8 object-contain" loading="lazy" />
                        <span v-else-if="option.icon === 'linkedin'" class="font-display text-sm font-bold text-ink-900">in</span>
                        <svg v-else-if="option.icon && ICONS[option.icon]" viewBox="0 0 24 24" class="h-5 w-5 text-ink-700" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <path :d="ICONS[option.icon]" />
                        </svg>
                        <span v-else class="font-display text-xs font-bold text-ink-700">{{ initials(option.label) }}</span>
                    </span>
                    <span class="min-w-0 leading-snug">{{ option.label }}</span>
                </label>
            </div>
        </div>

        <p v-if="error" :id="`${id}-error`" class="field-error">{{ error }}</p>
    </fieldset>
</template>
