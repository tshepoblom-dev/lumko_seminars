<script setup>
// The credential journey as a rail: Register > Attend > Pay > Verify > Ticket.
// Stages come from credentialStages() in lib/eventFormat.js, or are passed
// with every state 'todo' to explain the route before anyone registers.
defineProps({
    stages: { type: Array, required: true }, // [{ key, label, state: done | current | todo }]
    onStage: { type: Boolean, default: false },
    label: { type: String, default: 'Your registration journey' },
});
</script>

<template>
    <ol :aria-label="label" class="grid auto-cols-fr grid-flow-col gap-1">
        <li
            v-for="(s, i) in stages"
            :key="s.key"
            class="min-w-0"
            :aria-current="s.state === 'current' ? 'step' : undefined"
        >
            <span
                aria-hidden="true"
                class="block h-1"
                :class="{
                    'bg-verified-500': s.state === 'done' && s.key === 'ticket',
                    'bg-ink-900': s.state === 'done' && s.key !== 'ticket' && !onStage,
                    'bg-white': s.state === 'done' && s.key !== 'ticket' && onStage,
                    'bg-signal-500': s.state === 'current',
                    'bg-rule': s.state === 'todo' && !onStage,
                    'bg-white/20': s.state === 'todo' && onStage,
                }"
            ></span>
            <span class="mt-2 flex items-baseline gap-1.5 text-meta">
                <span class="tnum hidden text-[0.6875rem] sm:inline" :class="onStage ? 'text-white/60' : 'text-ink-400'">{{ String(i + 1).padStart(2, '0') }}</span>
                <span
                    class="truncate"
                    :class="[
                        s.state === 'current' ? 'font-semibold' : '',
                        onStage
                            ? (s.state === 'todo' ? 'text-white/60' : 'text-white')
                            : (s.state === 'todo' ? 'text-ink-400' : 'text-ink-900'),
                    ]"
                >{{ s.label }}</span>
                <span class="sr-only">
                    {{ s.state === 'done' ? '(complete)' : s.state === 'current' ? '(current step)' : '(to come)' }}
                </span>
            </span>
        </li>
    </ol>
</template>
