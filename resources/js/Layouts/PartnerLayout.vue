<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { partnerNav } from '@/lib/portalNav';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// The partner portal frame: PortalLayout with the partner's tabs. Pages for
// one event pass `event` and the tier's `features`.
const props = defineProps({
    partner: { type: Object, required: true }, // { slug, name }
    event: { type: Object, default: null },
    features: { type: Array, default: () => [] },
});

const nav = computed(() => partnerNav(props.partner, props.event, props.features));

// Super admins can open any partner's portal. Say so, so nobody mistakes
// it for their own team or acts on the partner's behalf by accident.
const page = usePage();
const staffView = computed(() => !(page.props.auth?.portals?.partners ?? []).some((p) => p.slug === props.partner.slug));
</script>

<template>
    <PortalLayout
        :area="`${partner.name} · Partner portal`"
        :current="`partner:${partner.slug}`"
        :home-href="route('partner.dashboard', partner.slug)"
        :nav="nav"
    >
        <template v-if="staffView" #notice>
            <div class="no-print bg-amber-100 text-amber-950" role="note" data-staff-view>
                <div class="shell flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2 text-sm">
                    <span>You're viewing <strong>{{ partner.name }}</strong>'s portal as Lumko staff.</span>
                    <Link :href="`${route('admin.partners.edit', partner.slug)}#portal`" class="font-medium underline">Back to admin</Link>
                </div>
            </div>
        </template>
        <slot />
    </PortalLayout>
</template>
