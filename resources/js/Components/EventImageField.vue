<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';

// One landing-page photo slot on the admin event form. Works on an Inertia
// form that carries `<kind>_image`, `<kind>_image_alt` and, when editing,
// `remove_<kind>_image`.
const props = defineProps({
    form: { type: Object, required: true },
    kind: { type: String, required: true }, // hero | venue
    label: { type: String, required: true },
    hint: { type: String, default: null },
    currentPath: { type: String, default: null }, // stored path, when editing
});

const fileKey = computed(() => `${props.kind}_image`);
const altKey = computed(() => `${props.kind}_image_alt`);
const removeKey = computed(() => `remove_${props.kind}_image`);

// Preview the picked file before it is uploaded.
const preview = ref(null);
watch(() => props.form[fileKey.value], (file) => {
    if (preview.value) URL.revokeObjectURL(preview.value);
    preview.value = file ? URL.createObjectURL(file) : null;
});
onBeforeUnmount(() => { if (preview.value) URL.revokeObjectURL(preview.value); });

const shown = computed(() => {
    if (preview.value) return preview.value;
    if (props.currentPath && !props.form[removeKey.value]) return `/storage/${props.currentPath}`;
    return null;
});

const hasImage = computed(() => !!props.form[fileKey.value] || (!!props.currentPath && !props.form[removeKey.value]));
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-[12rem_1fr]">
        <div class="relative aspect-[3/2] overflow-hidden bg-ink-900">
            <img v-if="shown" :src="shown" :alt="form[altKey] || ''" class="absolute inset-0 h-full w-full object-cover" />
            <p v-else class="absolute inset-0 flex items-center justify-center px-3 text-center text-meta text-white/60">
                No photo. The page shows a navy panel instead.
            </p>
        </div>

        <div class="space-y-4">
            <FormField
                :id="`event-${kind}-image`"
                :label="currentPath ? `Replace ${label.toLowerCase()}` : label"
                :hint="hint"
                :error="form.errors[fileKey]"
                v-slot="{ id, describedBy, invalid }"
            >
                <input
                    :id="id"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="block w-full max-w-full text-sm text-ink-700 file:mr-3 file:min-h-9 file:rounded-ctl file:border file:border-rule-strong file:bg-white file:px-3 file:text-sm file:font-medium file:text-ink-800 hover:file:border-ink-900"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                    @input="form[fileKey] = $event.target.files[0] ?? null"
                />
            </FormField>

            <FormField
                v-if="hasImage"
                :id="`event-${kind}-image-alt`"
                label="Describe the photo"
                hint="Read out by screen readers, for example: The lodge garden at sunrise."
                :error="form.errors[altKey]"
                v-slot="{ id, describedBy, invalid }"
            >
                <TextInput :id="id" v-model="form[altKey]" type="text" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <label v-if="currentPath && removeKey in form" class="flex items-center gap-2 text-sm text-ink-700">
                <Checkbox v-model:checked="form[removeKey]" />
                Remove this photo
            </label>
        </div>
    </div>
</template>
