<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

// Every area this account can open (admin, speaker portal, each partner's
// portal, the attendee area), for people who are more than one of these.
// Hidden when the attendee area is the only one.
const props = defineProps({
    current: { type: String, required: true }, // admin | speaker | attendee | partner:{slug}
    onStage: { type: Boolean, default: true },
});

const page = usePage();
const portals = computed(() => page.props.auth?.portals);

const areas = computed(() => {
    const p = portals.value;
    if (!p) return [];

    const list = [];
    if (p.admin) list.push({ key: 'admin', label: 'Admin', href: route('admin.dashboard') });
    if (p.speaker) list.push({ key: 'speaker', label: 'Speaker portal', href: route('speaker.dashboard') });
    for (const partner of p.partners ?? []) {
        list.push({ key: `partner:${partner.slug}`, label: `${partner.name} (partner)`, href: route('partner.dashboard', partner.slug) });
    }
    list.push({ key: 'attendee', label: 'Attendee area', href: route('attendee.dashboard') });

    return list;
});

const open = ref(false);
const root = ref(null);

function onDocClick(e) {
    if (open.value && root.value && !root.value.contains(e.target)) open.value = false;
}
function onKey(e) {
    if (e.key === 'Escape') open.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div v-if="areas.length > 1" ref="root" class="relative">
        <button
            type="button"
            :class="[
                'inline-flex min-h-10 items-center gap-1.5 rounded-ctl px-3 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-400',
                onStage ? 'text-white/80 hover:bg-white/10 hover:text-white' : 'text-ink-700 hover:bg-paper',
            ]"
            :aria-expanded="open"
            aria-haspopup="true"
            @click="open = !open"
        >
            Switch area
            <svg aria-hidden="true" viewBox="0 0 20 20" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="m6 8 4 4 4-4" />
            </svg>
        </button>
        <ul
            v-if="open"
            class="absolute right-0 z-30 mt-2 w-64 border-t-2 border-ink-900 bg-white py-1 text-sm text-ink-900 shadow-float"
        >
            <li v-for="area in areas" :key="area.key">
                <Link
                    :href="area.href"
                    :aria-current="area.key === current ? 'page' : undefined"
                    :class="[
                        'block px-4 py-2.5 hover:bg-paper focus:bg-paper focus:outline-none',
                        area.key === current ? 'font-semibold' : '',
                    ]"
                >
                    {{ area.label }}
                </Link>
            </li>
        </ul>
    </div>
</template>
