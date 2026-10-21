<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    partners: Array,
    referrals: { type: Array, default: () => [] },
});

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'logo', label: 'Logo' },
    { key: 'events', label: 'Events' },
    { key: 'members', label: 'Portal team' },
];

const page = usePage();
const isSuperAdmin = computed(() => !!page.props.auth?.user?.isSuperAdmin);

const portalLabel = (p) => {
    if (p.status === 'active') return `${p.members.length} ${p.members.length === 1 ? 'person' : 'people'}`;
    return p.status === 'invited' ? `Invited ${p.pending[0]?.invited_on}` : 'No account yet';
};
const portalVariant = (p) => ({ active: 'success', invited: 'warning', none: 'neutral' })[p.status];

const rand = (cents) => `R${(Number(cents ?? 0) / 100).toLocaleString('en-ZA', { maximumFractionDigits: 0 })}`;

const toDelete = ref(null);

function destroy() {
    router.delete(route('admin.partners.destroy', toDelete.value.slug), {
        onFinish: () => { toDelete.value = null; },
    });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Partners</h2>
        </template>

        <Head title="Partners" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-8 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="max-w-prose text-sm text-gray-600">
                        One shared list of businesses and organisations that support events. Take a partner on for an event, on a tier, from that event's Partners page.
                    </p>
                    <Button :href="route('admin.partners.create')">New partner</Button>
                </div>

                <DataTable :columns="columns" :rows="partners">
                    <template #cell-name="{ row }">
                        <span class="font-medium">{{ row.name }}</span>
                        <a v-if="row.website" :href="row.website" target="_blank" rel="noopener" class="block text-xs text-gray-600 hover:underline">{{ row.website }}</a>
                    </template>
                    <template #cell-logo="{ row }">
                        <span v-if="!row.logo_url" class="text-gray-500">None</span>
                        <Badge v-else :variant="row.logo_approved ? 'success' : 'warning'">{{ row.logo_approved ? 'Approved' : 'Pending approval' }}</Badge>
                    </template>
                    <template #cell-events="{ row }">
                        <span v-if="!row.events.length" class="text-gray-600">None</span>
                        <span v-else>
                            <template v-for="(e, i) in row.events" :key="e.id">
                                <Link :href="route('admin.events.partners.index', { event: e.id })" class="text-brand hover:underline">{{ e.name }}</Link><span v-if="i < row.events.length - 1">, </span>
                            </template>
                        </span>
                    </template>
                    <template #cell-members="{ row }">
                        <Badge :variant="portalVariant(row.portal)">{{ portalLabel(row.portal) }}</Badge>
                        <span class="mt-1 flex flex-wrap gap-x-3 text-xs">
                            <Link :href="`${route('admin.partners.edit', row.slug)}#portal`" class="text-brand hover:underline">Team<span class="sr-only"> for {{ row.name }}</span></Link>
                            <a v-if="isSuperAdmin" :href="route('partner.dashboard', row.slug)" class="text-brand hover:underline">Open portal<span class="sr-only"> for {{ row.name }}</span></a>
                        </span>
                    </template>
                    <template #actions="{ row }">
                        <Link :href="route('admin.partners.edit', row.slug)" class="text-brand hover:underline">Edit</Link>
                        <button type="button" class="ml-3 text-red-600 hover:underline" @click="toDelete = row">Delete</button>
                    </template>
                    <template #empty>
                        <EmptyState title="No partners yet" description="Add the businesses that support your events.">
                            <template #action>
                                <Button :href="route('admin.partners.create')" size="sm">New partner</Button>
                            </template>
                        </EmptyState>
                    </template>
                </DataTable>

                <section v-if="referrals.length" class="rounded-lg bg-white p-6 shadow" aria-labelledby="referrals-title">
                    <h3 id="referrals-title" class="text-lg font-semibold text-gray-800">Referrals across all events</h3>
                    <p class="mt-1 text-sm text-gray-600">For settling partner rewards. Partners see the counts, never the deal values.</p>
                    <table class="mt-3 min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-gray-600">
                            <tr>
                                <th scope="col" class="py-2 pr-4 font-medium">Partner</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">Referred</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">Took part</th>
                                <th scope="col" class="py-2 pr-4 text-right font-medium">Clients</th>
                                <th scope="col" class="py-2 text-right font-medium">Deal value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in referrals" :key="r.partner_id">
                                <td class="py-2 pr-4">{{ r.name }}</td>
                                <td class="tnum py-2 pr-4 text-right">{{ r.referred }}</td>
                                <td class="tnum py-2 pr-4 text-right">{{ r.took_part }}</td>
                                <td class="tnum py-2 pr-4 text-right">{{ r.converted }}</td>
                                <td class="tnum py-2 text-right">{{ rand(r.deal_value_cents) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>

        <Modal :show="!!toDelete" title="Delete partner" @close="toDelete = null">
            <p class="text-sm text-gray-600">
                Delete <strong>{{ toDelete?.name }}</strong>? It is removed from every event, its team loses portal access, and its leads are deleted. This cannot be undone.
            </p>
            <template #footer>
                <Button variant="secondary" @click="toDelete = null">Cancel</Button>
                <Button variant="danger" @click="destroy">Delete</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
