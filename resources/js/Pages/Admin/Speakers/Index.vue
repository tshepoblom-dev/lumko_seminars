<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import InvitePortalModal from '@/Components/Admin/InvitePortalModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    speakers: Array,
});

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'title', label: 'Title' },
    { key: 'organisation', label: 'Organisation' },
    { key: 'photo', label: 'Photo' },
    { key: 'events', label: 'Events' },
    { key: 'portal', label: 'Speaker portal' },
];

const portalLabels = { active: 'Active', invited: 'Invited', not_invited: 'Not invited' };
const portalVariants = { active: 'success', invited: 'warning', not_invited: 'neutral' };

// Confirmed before sending (InvitePortalModal): who, and at which address.
const inviting = ref(null);

function invite(speaker) {
    inviting.value = speaker;
}

const speakerToUnlink = ref(null);

function unlink() {
    router.delete(route('admin.speakers.portal-revoke', speakerToUnlink.value.id), {
        preserveScroll: true,
        onFinish: () => { speakerToUnlink.value = null; },
    });
}

const speakerToDelete = ref(null);

function confirmDelete(speaker) {
    speakerToDelete.value = speaker;
}

function destroy() {
    router.delete(route('admin.speakers.destroy', speakerToDelete.value.id), {
        onFinish: () => { speakerToDelete.value = null; },
    });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Speakers</h2>
        </template>

        <Head title="Speakers" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="max-w-prose text-sm text-gray-600">
                        One shared list of speakers for every event. Link a speaker to an event from that event's Speakers page.
                    </p>
                    <Button :href="route('admin.speakers.create')">New speaker</Button>
                </div>

                <DataTable :columns="columns" :rows="speakers">
                    <template #cell-name="{ row }">
                        <div class="flex items-center gap-2">
                            <img
                                v-if="row.photo_path"
                                :src="`/storage/${row.photo_path}`"
                                :alt="row.photo_alt ?? ''"
                                class="h-8 w-8 rounded-full object-cover"
                            />
                            <span>{{ row.name }}</span>
                        </div>
                    </template>
                    <template #cell-photo="{ row }">
                        <span v-if="!row.photo_path" class="text-gray-500">None</span>
                        <Badge v-else :variant="row.photo_approved ? 'success' : 'warning'">
                            {{ row.photo_approved ? 'Approved' : 'Pending approval' }}
                        </Badge>
                    </template>
                    <template #cell-events="{ row }">
                        <span v-if="!row.events.length" class="text-gray-600">Not linked</span>
                        <span v-else>
                            <template v-for="(e, i) in row.events" :key="e.id">
                                <Link :href="route('admin.events.speakers.index', { event: e.id })" class="text-brand hover:underline">{{ e.name }}</Link><span v-if="i < row.events.length - 1">, </span>
                            </template>
                        </span>
                    </template>
                    <template #cell-portal="{ row }">
                        <Badge :variant="portalVariants[row.portal_status]">{{ portalLabels[row.portal_status] }}</Badge>
                        <p v-if="row.portal.status === 'invited'" class="mt-0.5 text-xs text-gray-600">
                            Sent {{ row.portal.invited_on }} to {{ row.portal.invited_to }}, works until {{ row.portal.expires_on }}
                        </p>
                        <p v-else-if="row.portal.status === 'active' && row.portal.account_email" class="mt-0.5 text-xs text-gray-600">
                            {{ row.portal.account_email }}
                        </p>
                        <div class="mt-1 text-sm">
                            <button
                                v-if="row.portal_status !== 'active' && row.has_contact_email"
                                type="button"
                                class="text-brand hover:underline"
                                @click="invite(row)"
                            >
                                {{ row.portal_status === 'invited' ? 'Resend invitation' : 'Invite' }}
                            </button>
                            <span v-else-if="row.portal_status !== 'active'" class="text-gray-600">Add a contact email to invite</span>
                            <button v-else type="button" class="text-red-600 hover:underline" @click="speakerToUnlink = row">Remove access</button>
                        </div>
                    </template>
                    <template #actions="{ row }">
                        <Link :href="route('admin.speakers.edit', row.id)" class="text-brand hover:underline">Edit</Link>
                        <button type="button" class="ml-3 text-red-600 hover:underline" @click="confirmDelete(row)">Delete</button>
                    </template>
                    <template #empty>
                        <EmptyState title="No speakers yet" description="Add a speaker to feature them on the programme.">
                            <template #action>
                                <Button :href="route('admin.speakers.create')" size="sm">New speaker</Button>
                            </template>
                        </EmptyState>
                    </template>
                </DataTable>
            </div>
        </div>

        <InvitePortalModal
            kind="speaker"
            :show="!!inviting"
            :action="inviting ? route('admin.speakers.portal-invite', inviting.id) : null"
            :recipients="inviting ? [{ name: inviting.name, email: inviting.contact_email }] : []"
            :resend="inviting?.portal_status === 'invited'"
            @close="inviting = null"
        />

        <Modal :show="!!speakerToUnlink" title="Remove speaker portal access" @close="speakerToUnlink = null">
            <p class="text-sm text-gray-600">
                <strong>{{ speakerToUnlink?.name }}</strong> will no longer be able to open the speaker portal. Their account, their shared resources and their stage links are not affected.
            </p>
            <template #footer>
                <Button variant="secondary" @click="speakerToUnlink = null">Cancel</Button>
                <Button variant="danger" @click="unlink">Remove access</Button>
            </template>
        </Modal>

        <Modal :show="!!speakerToDelete" title="Delete speaker" @close="speakerToDelete = null">
            <p class="text-sm text-gray-600">
                Delete <strong>{{ speakerToDelete?.name }}</strong>? This cannot be undone.
            </p>
            <template #footer>
                <Button variant="secondary" @click="speakerToDelete = null">Cancel</Button>
                <Button variant="danger" @click="destroy">Delete</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
