<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { computed } from 'vue';

// One of an event's own registration questions (RegistrationForm::schema).
// Groups of choices are a fieldset with a legend; single controls go
// through FormField, so labels, hints and errors are wired the same way as
// the rest of the form.
const props = defineProps({
    question: { type: Object, required: true }, // { id, type, label, help_text, required, options, settings }
    modelValue: { default: null },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const value = computed({
    get: () => props.modelValue,
    set: (v) => emit('update:modelValue', v),
});

const fieldId = computed(() => `question-${props.question.id}`);
const hint = computed(() => {
    const parts = [];
    if (props.question.help_text) parts.push(props.question.help_text);
    if (props.question.type === 'multi_choice' && props.question.settings?.max_selections) {
        parts.push(`Choose up to ${props.question.settings.max_selections}.`);
    }
    if (props.question.type === 'file') {
        parts.push(`PDF, Word, Excel, PowerPoint or an image, up to ${Math.round((props.question.settings?.max_kb ?? 5120) / 1024)} MB.`);
    }
    if (!props.question.required) parts.push('Optional');
    return parts.join(' ') || null;
});

const asDropdown = computed(() => props.question.settings?.display === 'dropdown'
    || (props.question.settings?.display !== 'radio' && (props.question.options?.length ?? 0) > 6));

const errorId = computed(() => `${fieldId.value}-error`);
const hintId = computed(() => `${fieldId.value}-hint`);
const groupDescribedBy = computed(() => [hint.value ? hintId.value : null, props.error ? errorId.value : null].filter(Boolean).join(' ') || undefined);

function pickFile(event) {
    value.value = event.target.files?.[0] ?? null;
}
</script>

<template>
    <!-- Choices and yes/no: a group of controls under one legend. -->
    <fieldset v-if="['multi_choice', 'yes_no'].includes(question.type) || (question.type === 'single_choice' && !asDropdown)" :aria-describedby="groupDescribedBy">
        <legend class="field-label mb-1.5">
            {{ question.label }}
            <span v-if="question.required" class="text-red-700" aria-hidden="true">*</span>
        </legend>
        <p v-if="hint" :id="hintId" class="field-hint mb-2">{{ hint }}</p>

        <div v-if="question.type === 'multi_choice'" class="grid gap-2 sm:grid-cols-2">
            <label v-for="option in question.options" :key="option.id" class="choice text-sm">
                <Checkbox v-model:checked="value" :value="option.id" />
                <span>{{ option.label }}</span>
            </label>
        </div>

        <div v-else-if="question.type === 'single_choice'" class="grid gap-2 sm:grid-cols-2">
            <label v-for="option in question.options" :key="option.id" class="choice text-sm">
                <input v-model="value" type="radio" :name="fieldId" :value="option.id" />
                <span>{{ option.label }}</span>
            </label>
        </div>

        <div v-else class="flex gap-3">
            <label class="choice text-sm">
                <input v-model="value" type="radio" :name="fieldId" :value="true" />
                <span>Yes</span>
            </label>
            <label class="choice text-sm">
                <input v-model="value" type="radio" :name="fieldId" :value="false" />
                <span>No</span>
            </label>
        </div>

        <p v-if="error" :id="errorId" class="field-error">{{ error }}</p>
    </fieldset>

    <!-- Everything else: one labelled control. -->
    <FormField v-else :id="fieldId" :label="question.label" :hint="hint" :error="error" :required="question.required" :announce="false" v-slot="{ id, describedBy, invalid }">
        <select v-if="question.type === 'single_choice'" :id="id" v-model="value" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid">
            <option value="">Choose…</option>
            <option v-for="option in question.options" :key="option.id" :value="option.id">{{ option.label }}</option>
        </select>
        <TextInput v-else-if="question.type === 'short_text'" :id="id" v-model="value" maxlength="255" :aria-describedby="describedBy" :aria-invalid="invalid" />
        <textarea v-else-if="question.type === 'long_text'" :id="id" v-model="value" rows="4" maxlength="2000" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
        <TextInput
            v-else-if="question.type === 'number'"
            :id="id"
            v-model="value"
            type="number"
            inputmode="decimal"
            :min="question.settings?.min ?? undefined"
            :max="question.settings?.max ?? undefined"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
        />
        <input v-else-if="question.type === 'date'" :id="id" v-model="value" type="date" class="field w-full sm:w-56" :aria-describedby="describedBy" :aria-invalid="invalid" />
        <template v-else-if="question.type === 'file'">
            <input
                :id="id"
                type="file"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg"
                class="block w-full max-w-full text-sm text-ink-700"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                @change="pickFile"
            />
            <p v-if="value && value.name" class="mt-1.5 text-meta text-ink-500">Selected: {{ value.name }}</p>
        </template>
    </FormField>
</template>
