<script setup>
import { computed } from 'vue';

const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    // Set false where the form already has a focused error summary that
    // announces itself, so errors are not read out twice.
    announce: { type: Boolean, default: true },
});

// Wires up aria-describedby so the input slot's element only needs
// :id="id" and :aria-describedby="describedBy" — the hint/error text and
// their ids are owned entirely by this component.
const describedBy = computed(() => {
    const ids = [];
    if (props.hint) ids.push(`${props.id}-hint`);
    if (props.error) ids.push(`${props.id}-error`);
    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div>
        <label :for="id" class="field-label mb-1.5">
            {{ label }}
            <span v-if="required" class="text-red-700" aria-hidden="true">*</span>
        </label>
        <!-- No wrapper: the error sits immediately after the control. -->
        <slot :id="id" :described-by="describedBy" :invalid="!!error" />
        <p v-if="hint && !error" :id="`${id}-hint`" class="field-hint">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" :role="announce ? 'alert' : undefined" class="field-error">{{ error }}</p>
    </div>
</template>
