<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, useForm } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

const props = defineProps({ payment: Object });

const approveForm = useForm({ note: '' });
const rejectForm = useForm({ note: '' });

function approve() {
    approveForm.post(eventRoute('admin.payments.approve', { payment: props.payment.id }));
}

function reject() {
    rejectForm.post(eventRoute('admin.payments.reject', { payment: props.payment.id }));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Review payment</h2>
        </template>

        <Head title="Review payment" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow">
                    <p class="font-semibold">{{ payment.attendee.name }}</p>
                    <p class="text-sm text-gray-600">{{ payment.attendee.email }} &middot; {{ payment.attendee.organisation }}</p>
                    <p v-if="payment.complimentary" class="mt-2">
                        Amount: Complimentary
                        <span class="text-sm text-gray-600">(invitation code<template v-if="payment.voucher_recipient"> issued to {{ payment.voucher_recipient }}</template>)</span>
                    </p>
                    <p v-else class="mt-2">Amount: R{{ payment.amount.toFixed(0) }}</p>
                    <p>Status: <span class="font-medium">{{ payment.status_label }}</span></p>

                    <a
                        v-if="payment.proof_uploaded"
                        :href="eventRoute('admin.payments.proof', { payment: payment.id })"
                        target="_blank"
                        class="mt-3 inline-block text-signal-700 underline"
                    >
                        View uploaded proof of payment
                    </a>
                    <p v-else class="mt-3 text-sm text-gray-500">No proof uploaded yet.</p>
                </div>

                <div v-if="payment.status !== 'approved'" class="grid gap-4 sm:grid-cols-2">
                    <form class="space-y-2 rounded-lg border border-green-200 p-4" @submit.prevent="approve">
                        <label class="block text-sm font-medium" for="admin-payments-show-1">Approve (optional note)</label>
                        <textarea id="admin-payments-show-1" v-model="approveForm.note" class="w-full rounded border-gray-300" rows="2"></textarea>
                        <button type="submit" class="rounded bg-green-700 px-4 py-2 text-sm text-white">
                            Approve payment
                        </button>
                    </form>
                    <form class="space-y-2 rounded-lg border border-red-200 p-4" @submit.prevent="reject">
                        <label class="block text-sm font-medium" for="admin-payments-show-2">Reject (reason required)</label>
                        <textarea id="admin-payments-show-2" v-model="rejectForm.note" class="w-full rounded border-gray-300" rows="2"></textarea>
                        <p v-if="rejectForm.errors.note" class="text-sm text-red-600">{{ rejectForm.errors.note }}</p>
                        <button type="submit" class="rounded bg-red-700 px-4 py-2 text-sm text-white">
                            Reject payment
                        </button>
                    </form>
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <p class="font-semibold">History</p>
                    <ul class="mt-2 space-y-2 text-sm text-gray-600">
                        <li v-for="(entry, i) in payment.history" :key="i">
                            {{ entry.at }} — {{ entry.from ?? 'created' }} → {{ entry.to }}
                            ({{ entry.changed_by }})
                            <span v-if="entry.note">: {{ entry.note }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
