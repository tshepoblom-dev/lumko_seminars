<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Button from '@/Components/Button.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head } from '@inertiajs/vue3';

defineProps({
    reason: String, // not_agora | no_channel
    provider: { type: String, default: null },
});

const eventRoute = useEventRoute();
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Broadcast</h2>
        </template>

        <Head title="Broadcast" />

        <div class="py-6">
            <div class="max-w-2xl rounded-lg bg-white p-6 shadow">
                <p class="font-medium text-gray-900">The broadcast console isn't set up for this event.</p>
                <p v-if="reason === 'not_agora'" class="mt-2 text-sm text-gray-700">
                    The console streams through Agora. This event's livestream is
                    {{ provider ? `set to ${provider.replaceAll('_', ' ')}` : 'not set up yet' }},
                    so there's nothing to broadcast from here.
                </p>
                <p v-else class="mt-2 text-sm text-gray-700">
                    This event uses Agora but has no channel name yet.
                </p>
                <div class="mt-4">
                    <Button variant="secondary" size="sm" :href="eventRoute('admin.events.edit')">Open livestream settings</Button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
