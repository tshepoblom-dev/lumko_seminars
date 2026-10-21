<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { formatDateTime } from '@/lib/communications';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps({
    registrationOpen: { type: Boolean, default: false },
    eventIsDraft: { type: Boolean, default: false },
    summary: { type: Object, required: true },
    vouchers: { type: Array, default: () => [] },
});

const eventRoute = useEventRoute();

const statusVariant = {
    unused: 'neutral',
    sent: 'info',
    redeemed: 'success',
    revoked: 'danger',
    closed: 'warning',
};

const columns = [
    { key: 'code', label: 'Code' },
    { key: 'recipient', label: 'Invited' },
    { key: 'status', label: 'Status' },
    { key: 'redeemed_by', label: 'Redeemed by' },
];

// ── Issue a code ───────────────────────────────────────────────────────
const form = useForm({
    recipient_name: '',
    recipient_email: '',
    email_locked: true,
    send_now: true,
    note: '',
});

// Reserving the code for an address, and emailing it, both need one.
const hasEmail = computed(() => form.recipient_email.trim() !== '');

function issue() {
    form
        .transform((data) => ({
            ...data,
            email_locked: hasEmail.value && data.email_locked,
            send_now: hasEmail.value && data.send_now,
        }))
        .post(eventRoute('admin.vouchers.store'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
}

// ── Copy a code ────────────────────────────────────────────────────────
const copiedId = ref(null);
const copyStatus = ref('');
let copyTimer;

async function copy(voucher) {
    try {
        await navigator.clipboard.writeText(voucher.code);
        copiedId.value = voucher.id;
        copyStatus.value = `Code for ${voucher.recipient_name} copied`;
    } catch {
        copiedId.value = null;
        copyStatus.value = 'Copying is not available here; select the code instead';
    }
    clearTimeout(copyTimer);
    copyTimer = setTimeout(() => { copiedId.value = null; copyStatus.value = ''; }, 2500);
}

// ── Send / revoke ──────────────────────────────────────────────────────
const toSend = ref(null);
const toRevoke = ref(null);
const busy = ref(false);

function send() {
    busy.value = true;
    router.post(eventRoute('admin.vouchers.send', { voucher: toSend.value.id }), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; toSend.value = null; },
    });
}

function revoke() {
    busy.value = true;
    router.post(eventRoute('admin.vouchers.revoke', { voucher: toRevoke.value.id }), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; toRevoke.value = null; },
    });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Vouchers</h2>
        </template>

        <Head title="Vouchers" />

        <div class="space-y-6 py-6">
            <p class="max-w-prose text-sm text-ink-600">
                Honorary invitations. Each code gives one person a complimentary in-person ticket: they enter it when
                they register (or from their account, if they have already registered), the payment is waived and
                their ticket is confirmed straight away.
            </p>

            <p v-if="!registrationOpen" class="max-w-prose border border-rule border-t-2 border-t-amber-500 bg-white p-4 text-sm text-ink-800">
                <template v-if="eventIsDraft">
                    This event isn't published yet. You can create codes now; they can be used, and emailed, once registration opens.
                </template>
                <template v-else>Registration for this event has closed, so its codes can no longer be used.</template>
            </p>

            <dl class="grid max-w-2xl grid-cols-2 gap-px border border-rule bg-rule sm:grid-cols-4">
                <div v-for="[label, value] in [['Issued', summary.issued], ['Redeemed', summary.redeemed], ['Outstanding', summary.outstanding], ['Revoked', summary.revoked]]" :key="label" class="bg-white px-4 py-3">
                    <dt class="eyebrow text-ink-500">{{ label }}</dt>
                    <dd class="tnum mt-1 font-display text-2xl text-ink-900">{{ value }}</dd>
                </div>
            </dl>

            <Card title="Issue an invitation" class="max-w-2xl">
                <form class="space-y-4" @submit.prevent="issue">
                    <FormField id="voucher-name" label="Name" hint="Who the invitation is for. Used to greet them in the email." :error="form.errors.recipient_name" required v-slot="{ id, describedBy, invalid }">
                        <input :id="id" v-model="form.recipient_name" type="text" class="field w-full" autocomplete="off" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>

                    <FormField id="voucher-email" label="Email" hint="Optional. Needed to reserve the code for them or to email the invitation." :error="form.errors.recipient_email" v-slot="{ id, describedBy, invalid }">
                        <input :id="id" v-model="form.recipient_email" type="email" class="field w-full" autocomplete="off" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>

                    <fieldset class="space-y-2" :disabled="!hasEmail">
                        <legend class="sr-only">Email options</legend>
                        <label class="flex items-start gap-3 text-sm" :class="hasEmail ? 'text-ink-800' : 'text-ink-500'">
                            <input v-model="form.email_locked" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand" />
                            <span>
                                Only this email address can use the code
                                <span class="block text-meta text-ink-500">Untick to let them pass it on: then whoever registers with it first gets the ticket.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-3 text-sm" :class="hasEmail ? 'text-ink-800' : 'text-ink-500'">
                            <input v-model="form.send_now" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand focus:ring-brand" />
                            <span>Email the invitation now</span>
                        </label>
                    </fieldset>

                    <FormField id="voucher-note" label="Internal note" hint="Optional, for staff only (for example who asked for the invitation)." :error="form.errors.note" v-slot="{ id, describedBy, invalid }">
                        <input :id="id" v-model="form.note" type="text" maxlength="500" class="field w-full" autocomplete="off" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>

                    <Button type="submit" :disabled="form.processing">
                        {{ hasEmail && form.send_now ? 'Create and email invitation' : 'Create code' }}
                    </Button>
                </form>
            </Card>

            <p class="sr-only" aria-live="polite">{{ copyStatus }}</p>

            <DataTable :columns="columns" :rows="vouchers">
                <template #cell-code="{ row }">
                    <span class="flex items-center gap-2 whitespace-nowrap">
                        <span class="tnum font-medium tracking-wide text-ink-900" :class="{ 'line-through': row.status === 'revoked' }">{{ row.code }}</span>
                        <button
                            v-if="row.status !== 'revoked'"
                            type="button"
                            class="link text-meta"
                            :aria-label="`Copy the code for ${row.recipient_name}`"
                            @click="copy(row)"
                        >
                            {{ copiedId === row.id ? 'Copied' : 'Copy' }}
                        </button>
                    </span>
                </template>
                <template #cell-recipient="{ row }">
                    <span class="font-medium text-ink-900">{{ row.recipient_name }}</span>
                    <p v-if="row.recipient_email" class="text-meta text-ink-600">{{ row.recipient_email }}</p>
                    <p class="text-meta text-ink-500">{{ row.email_locked ? 'Reserved for this email' : 'Anyone with the code' }}</p>
                    <p v-if="row.note" class="text-meta text-ink-500">{{ row.note }}</p>
                </template>
                <template #cell-status="{ row }">
                    <Badge :variant="statusVariant[row.status] ?? 'neutral'">{{ row.status_label }}</Badge>
                    <p v-if="row.sent_at" class="text-meta text-ink-500">Emailed {{ formatDateTime(row.sent_at) }}</p>
                    <p class="text-meta text-ink-500">Created {{ formatDateTime(row.created_at) }}<template v-if="row.created_by"> by {{ row.created_by }}</template></p>
                </template>
                <template #cell-redeemed_by="{ row }">
                    <template v-if="row.redeemed_by">
                        <Link :href="row.redeemed_by.url" class="link">{{ row.redeemed_by.name }}</Link>
                        <p class="text-meta text-ink-600">{{ formatDateTime(row.redeemed_at) }}</p>
                        <p v-if="!row.redeemed_by.account_ready" class="text-meta text-ink-500">Account not set up yet</p>
                    </template>
                    <span v-else class="text-ink-500">—</span>
                </template>
                <template #actions="{ row }">
                    <span class="inline-flex flex-wrap justify-end gap-3 text-sm">
                        <button v-if="row.can_send" type="button" class="link" @click="toSend = row">
                            {{ row.sent_at ? 'Resend' : 'Email' }}<span class="sr-only"> the invitation to {{ row.recipient_name }}</span>
                        </button>
                        <button v-if="row.can_revoke" type="button" class="text-red-700 hover:underline" @click="toRevoke = row">
                            Revoke<span class="sr-only"> the code for {{ row.recipient_name }}</span>
                        </button>
                    </span>
                </template>
                <template #empty>
                    <EmptyState title="No invitation codes yet" description="Codes you create here are listed with who redeemed them." />
                </template>
            </DataTable>
        </div>

        <Modal :show="!!toSend" title="Email the invitation" @close="toSend = null">
            <div class="space-y-3 text-sm text-ink-700">
                <p>
                    <strong>{{ toSend?.recipient_name }}</strong> ({{ toSend?.recipient_email }}) gets an email with the
                    code and a link that fills it in on the registration form.
                </p>
                <p v-if="toSend?.sent_at">It was already emailed on {{ formatDateTime(toSend.sent_at) }}; this sends the same code again.</p>
            </div>
            <template #footer>
                <Button variant="secondary" @click="toSend = null">Cancel</Button>
                <Button :disabled="busy" @click="send">Send email</Button>
            </template>
        </Modal>

        <Modal :show="!!toRevoke" title="Revoke invitation code" @close="toRevoke = null">
            <p class="text-sm text-ink-700">
                The code for <strong>{{ toRevoke?.recipient_name }}</strong> will stop working straight away. This can't be undone; you can issue a new code instead.
            </p>
            <template #footer>
                <Button variant="secondary" @click="toRevoke = null">Cancel</Button>
                <Button variant="danger" :disabled="busy" @click="revoke">Revoke code</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
