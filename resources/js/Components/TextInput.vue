<script setup>
import { onMounted, ref } from 'vue';

// Profile values can be null (an optional mobile, a blank job title).
const model = defineModel({ type: [String, Number], default: '' });

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="field"
        v-model="model"
        ref="input"
    />
</template>
