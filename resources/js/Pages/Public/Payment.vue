<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import CopyValue from '@/Components/CopyValue.vue';
import JourneyRail from '@/Components/JourneyRail.vue';
import { credentialStages, longDate, timeRange } from '@/lib/eventFormat';

const props = defineProps({
    event: Object,
    payment: Object,
    bankDetails: Object,
});

const form = useForm({ proof: null });

function submit() {
    form.post(route('payment.store'), { forceFormData: true });
}

const statusVariant = {
    pending: 'warning',
    under_review: 'info',
    approved: 'success',
    rejected: 'danger',
    cancelled: 'neutral',
};

const stages = computed(() => credentialStages({
    inPerson: true,
    paymentStatus: props.payment.status,
    proofUploaded: props.payment.proof_uploaded,
    ticketConfirmed: props.payment.status === 'approved',
}));

const amount = computed(() => `R${props.payment.amount.toFixed(0)}`);

// Upload area: the real file input stays in the form (and keeps its label);
// dragging a file onto the area hands it to the same input.
const fileInput = ref(null);
const dragging = ref(false);

function pick(file) {
    form.proof = file ?? null;
    form.clearErrors('proof');
}

function onDrop(event) {
    dragging.value = false;
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    try {
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.value.files = dt.files;
    } catch {
        // Older browsers: the file is still attached to the form below.
    }
    pick(file);
}

function clearFile() {
    if (fileInput.value) fileInput.value.value = '';
    pick(null);
}

const fileSize = computed(() => {
    const bytes = form.proof?.size;
    if (!bytes) return '';
    return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
});
</script>

<template>
    <AttendeeLayout>
    <Head title="Payment" />

    <div class="shell pb-20 pt-10 sm:pt-14">
        <!-- Running head: where this payment sits in the journey. -->
        <header class="grid gap-6 border-b border-ink-900 pb-8 lg:grid-cols-12 lg:items-end">
            <div class="lg:col-span-7">
                <p class="eyebrow text-ink-400">Payment &middot; {{ event.name }}</p>
                <h1 class="display mt-3 text-display-md text-ink-900">
                    <template v-if="payment.status === 'approved'">Payment approved</template>
                    <template v-else>Secure your seat</template>
                </h1>
                <p v-if="event.starts_at" class="tnum mt-3 text-sm text-ink-500">
                    {{ longDate(event.starts_at) }} &middot; {{ timeRange(event.starts_at, event.ends_at) }}<template v-if="event.venue_name"> &middot; {{ event.venue_name }}</template>
                </p>
            </div>
            <div class="lg:col-span-5">
                <p class="flex items-center justify-between gap-4 text-sm">
                    <span class="text-ink-500">Status</span>
                    <Badge :variant="statusVariant[payment.status] ?? 'neutral'">{{ payment.status_label }}</Badge>
                </p>
                <JourneyRail :stages="stages" class="mt-4" />
            </div>
        </header>

        <div
            v-if="payment.admin_note"
            role="alert"
            class="mt-8 border border-rule border-t-2 border-t-red-700 bg-white p-4 text-sm"
        >
            <p class="font-semibold text-ink-900">Your proof of payment was not accepted</p>
            <p class="mt-1 text-ink-700">Reviewer note: {{ payment.admin_note }}</p>
        </div>

        <div class="mt-10 grid gap-12 lg:grid-cols-12 lg:gap-6">
            <!-- 1. Remittance slip -->
            <section class="lg:col-span-6" aria-labelledby="remit-title">
                <p class="section-index">01</p>
                <h2 id="remit-title" class="mt-2 text-2xl text-ink-900">Pay by EFT</h2>

                <div class="mt-6 border border-rule bg-white shadow-lift">
                    <div class="flex items-end justify-between gap-4 border-b border-rule bg-paper px-5 py-5 sm:px-6">
                        <div>
                            <p class="eyebrow text-ink-400">Amount</p>
                            <p class="tnum mt-1 font-display text-display-sm text-ink-900" style="font-stretch: 82%; font-weight: 800">{{ amount }}</p>
                        </div>
                        <p class="text-right text-meta text-ink-500">In-person seat</p>
                    </div>

                    <dl class="ledger px-5 sm:px-6">
                        <template v-if="bankDetails.configured">
                            <CopyValue label="Account name" :value="bankDetails.account_name" />
                            <CopyValue label="Bank" :value="bankDetails.bank" />
                            <CopyValue v-if="bankDetails.account_type" label="Account type" :value="bankDetails.account_type" />
                            <CopyValue label="Account number" :value="bankDetails.account_number" />
                            <CopyValue label="Branch code" :value="bankDetails.branch_code" />
                        </template>
                        <CopyValue
                            label="Reference"
                            :value="bankDetails.reference"
                            emphasis
                            note="Please use this exact reference so we can match your payment."
                        />
                    </dl>

                    <p
                        v-if="!bankDetails.configured"
                        class="border-t border-rule border-t-2 border-t-amber-500 px-5 py-4 text-sm text-ink-800 sm:px-6"
                    >
                        Our banking details are being finalised and will be shared with you shortly.
                        Please do not pay until you receive them. Your reference is above.
                    </p>
                </div>
            </section>

            <!-- 2. Proof of payment -->
            <section class="lg:col-span-5 lg:col-start-8" aria-labelledby="proof-title">
                <p class="section-index">02</p>
                <h2 id="proof-title" class="mt-2 text-2xl text-ink-900">Upload proof of payment</h2>

                <div v-if="payment.status === 'approved'" class="mt-6 border-t-2 border-verified-500 pt-5">
                    <p class="text-[0.9375rem] text-ink-800">Your payment has been approved. Thank you.</p>
                    <Button :href="route('ticket.show')" arrow class="mt-5">View your ticket</Button>
                </div>

                <form v-else class="mt-6" @submit.prevent="submit">
                    <label
                        for="public-payment-1"
                        class="group relative block cursor-pointer border border-dashed px-5 py-8 text-center transition-colors has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-signal-500"
                        :class="[
                            dragging ? 'border-signal-500 bg-signal-50' : 'border-ink-300 bg-white hover:border-ink-900',
                            form.errors.proof ? 'border-red-700' : '',
                        ]"
                        @dragenter.prevent="dragging = true"
                        @dragover.prevent="dragging = true"
                        @dragleave.prevent="dragging = false"
                        @drop.prevent="onDrop"
                    >
                        <svg aria-hidden="true" viewBox="0 0 24 24" class="mx-auto h-7 w-7 text-ink-400 group-hover:text-ink-900" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M12 16V4m0 0-4.5 4.5M12 4l4.5 4.5M4 15v4.25c0 .41.34.75.75.75h14.5c.41 0 .75-.34.75-.75V15" />
                        </svg>
                        <span class="mt-3 block text-sm font-medium text-ink-900">Upload proof of payment (JPG, PNG or PDF, max 5MB)</span>
                        <span class="mt-1 block text-meta text-ink-500">Drop the file here, or choose it from your device</span>
                        <input
                            id="public-payment-1"
                            ref="fileInput"
                            type="file"
                            accept=".jpg,.jpeg,.png,.pdf"
                            class="sr-only"
                            :aria-invalid="!!form.errors.proof"
                            :aria-describedby="form.errors.proof ? 'proof-error' : undefined"
                            @input="pick($event.target.files[0])"
                        />
                    </label>

                    <div v-if="form.proof" class="mt-3 flex items-center justify-between gap-4 border border-rule bg-paper px-4 py-3 text-sm">
                        <span class="min-w-0">
                            <span class="block truncate font-medium text-ink-900">{{ form.proof.name }}</span>
                            <span class="tnum text-meta text-ink-500">{{ fileSize }}</span>
                        </span>
                        <button type="button" class="link shrink-0 text-meta" @click="clearFile">Remove</button>
                    </div>

                    <p v-if="form.errors.proof" id="proof-error" class="field-error">{{ form.errors.proof }}</p>

                    <Button type="submit" size="lg" :disabled="form.processing" class="mt-6 w-full">
                        {{ form.processing ? 'Uploading…' : (payment.proof_uploaded ? 'Resubmit proof of payment' : 'Submit proof of payment') }}
                    </Button>

                    <p class="mt-5 text-meta text-ink-500">
                        <template v-if="payment.proof_uploaded && payment.status === 'under_review'">
                            We have your proof of payment and it is being reviewed. You can replace it above if needed.
                        </template>
                        <template v-else>
                            Once your payment is approved, your ticket appears on your
                            <Link :href="route('ticket.show')" class="link">ticket page</Link>.
                        </template>
                    </p>
                    <p v-if="['pending', 'rejected'].includes(payment.status)" class="mt-3 text-meta text-ink-500">
                        Invited as our guest?
                        <Link :href="route('voucher.redeem')" class="link">Redeem your invitation code</Link>
                        instead of paying.
                    </p>
                </form>
            </section>
        </div>
    </div>
    </AttendeeLayout>
</template>
