<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const eventRoute = useEventRoute();

const props = defineProps({
    event: Object,
    registrationId: Number,
    attendee: Object,
    registration: Object,
    payment: Object,
    ticket: Object,
    consentTimeline: Array,
    engagement: Object,
    answers: { type: Array, default: () => [] },
    referral: { type: Object, default: () => ({ source: null, label: null }) },
    conversion: { type: Object, default: null },
    suggestedPartnerId: { type: Number, default: null },
    partnerOptions: { type: Array, default: () => [] },
});

const conversionForm = useForm({
    converted_on: props.conversion?.converted_on ?? new Date().toISOString().slice(0, 10),
    partner_id: props.conversion ? props.conversion.partner_id : props.suggestedPartnerId,
    deal_value: props.conversion?.deal_value ?? '',
    notes: props.conversion?.notes ?? '',
});

function saveConversion() {
    conversionForm.put(eventRoute('admin.attendees.conversion.update', { registration: props.registrationId }), { preserveScroll: true });
}

function removeConversion() {
    if (!confirm('Remove the client record? The partner credit goes with it.')) return;
    router.delete(eventRoute('admin.attendees.conversion.destroy', { registration: props.registrationId }), { preserveScroll: true });
}

const sending = ref(false);

function sendSetupLink() {
    router.post(eventRoute('admin.attendees.setup-link', { registration: props.registrationId }), {}, {
        preserveScroll: true,
        onStart: () => { sending.value = true; },
        onFinish: () => { sending.value = false; },
    });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ attendee.name }}</h2>
        </template>

        <Head :title="attendee.name" />

        <div class="py-12">
            <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                <Link :href="eventRoute('admin.attendees.index')" class="text-sm text-brand hover:underline">
                    ← Back to attendees
                </Link>

                <Card title="Profile">
                    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-gray-500">Email</dt><dd>{{ attendee.email }}</dd></div>
                        <div><dt class="text-gray-500">Mobile</dt><dd>{{ attendee.mobile ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Organisation</dt><dd>{{ attendee.organisation ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Job title</dt><dd>{{ attendee.job_title ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Registered</dt><dd>{{ registration.registered_at }}</dd></div>
                        <div><dt class="text-gray-500">Attendance mode</dt><dd>{{ registration.attendance_mode }}</dd></div>
                        <div><dt class="text-gray-500">Industry</dt><dd>{{ registration.industry ?? '—' }}</dd></div>
                        <div><dt class="text-gray-500">Organisation size</dt><dd>{{ registration.organisation_size ?? '—' }}</dd></div>
                    </dl>
                </Card>

                <Card title="Registration answers">
                    <p v-if="!answers.length" class="text-sm text-gray-500">No answers to this event's questions.</p>
                    <dl v-else class="space-y-3 text-sm">
                        <div v-for="a in answers" :key="a.id">
                            <dt class="text-gray-500">{{ a.question }}</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-gray-800">
                                <a
                                    v-if="a.is_file"
                                    :href="eventRoute('admin.attendees.answers.file', { registration: registrationId, answer: a.id })"
                                    class="text-brand hover:underline"
                                >{{ a.display }} (download)</a>
                                <template v-else>{{ a.display }}</template>
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card title="Account">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <p v-if="attendee.account_set_up">
                            <Badge variant="success">Set up</Badge>
                            <span class="ml-2 text-gray-700">Email confirmed and password chosen.</span>
                        </p>
                        <p v-else>
                            <Badge variant="warning">Not set up</Badge>
                            <span class="ml-2 text-gray-700">They haven't used their "confirm email &amp; set password" link yet.</span>
                        </p>
                        <Button
                            v-if="attendee.can_send_setup_link"
                            size="sm"
                            variant="secondary"
                            :disabled="sending"
                            @click="sendSetupLink"
                        >
                            Resend setup link
                        </Button>
                    </div>
                    <p v-if="attendee.can_send_setup_link" class="mt-2 text-xs text-gray-600">
                        Sends a fresh link to {{ attendee.email }}; any earlier link stops working.
                    </p>
                </Card>

                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <Card v-if="payment" title="Payment">
                        <p class="text-sm">Status: <span class="font-medium">{{ payment.status }}</span></p>
                        <p class="text-sm">Amount: {{ payment.complimentary ? 'Complimentary (invitation code)' : `R${Number(payment.amount).toFixed(0)}` }}</p>
                    </Card>
                    <Card v-if="ticket" title="Ticket">
                        <p class="text-sm">Status: <span class="font-medium capitalize">{{ ticket.status }}</span></p>
                        <p class="text-sm">
                            Checked in:
                            <span class="font-medium">{{ ticket.checked_in ? `Yes, ${ticket.checked_in_at} by ${ticket.checked_in_by ?? 'unknown staff'}` : 'No' }}</span>
                        </p>
                    </Card>
                </div>

                <Card title="Engagement">
                    <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-gray-500">Questions submitted</dt><dd class="text-lg font-semibold">{{ engagement.questions_submitted }}</dd></div>
                        <div><dt class="text-gray-500">Question votes</dt><dd class="text-lg font-semibold">{{ engagement.question_votes_cast }}</dd></div>
                        <div><dt class="text-gray-500">Poll responses</dt><dd class="text-lg font-semibold">{{ engagement.poll_responses }}</dd></div>
                        <div><dt class="text-gray-500">Resource views</dt><dd class="text-lg font-semibold">{{ engagement.resource_views }}</dd></div>
                    </dl>
                </Card>

                <Card title="Referral and client">
                    <p class="text-sm text-gray-700">
                        How they heard about us:
                        <span class="font-medium">{{ referral.label ?? 'Not given' }}</span>
                    </p>

                    <form class="mt-4 space-y-3 text-sm" @submit.prevent="saveConversion">
                        <p class="font-medium text-gray-800">{{ conversion ? 'Became a client' : 'Mark as a Lumko client' }}</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-gray-600">Date</span>
                                <input v-model="conversionForm.converted_on" type="date" required class="mt-1 w-full rounded border-gray-300" />
                            </label>
                            <label class="block">
                                <span class="text-gray-600">Credit to partner</span>
                                <select v-model="conversionForm.partner_id" class="mt-1 w-full rounded border-gray-300">
                                    <option :value="null">No partner</option>
                                    <option v-for="p in partnerOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-gray-600">Deal value (R, optional)</span>
                                <input v-model="conversionForm.deal_value" type="number" min="0" step="0.01" class="mt-1 w-full rounded border-gray-300" />
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="text-gray-600">Notes (optional)</span>
                                <textarea v-model="conversionForm.notes" rows="2" maxlength="2000" class="mt-1 w-full rounded border-gray-300"></textarea>
                            </label>
                        </div>
                        <p v-for="(message, field) in conversionForm.errors" :key="field" class="text-red-700">{{ message }}</p>
                        <p class="text-xs text-gray-600">The credited partner sees only how many of their referrals became clients, never who.</p>
                        <div class="flex gap-3">
                            <Button type="submit" size="sm" :disabled="conversionForm.processing">{{ conversion ? 'Save' : 'Mark as client' }}</Button>
                            <Button v-if="conversion" type="button" size="sm" variant="secondary" @click="removeConversion">Remove</Button>
                        </div>
                    </form>
                </Card>

                <Card title="Consent history">
                    <p v-if="!consentTimeline.length" class="text-sm text-gray-500">No consent records.</p>
                    <ul class="space-y-2 text-sm">
                        <li v-for="(record, i) in consentTimeline" :key="i" class="border-b border-gray-100 pb-2 last:border-0">
                            <span class="font-medium capitalize">{{ record.type.replace('_', ' ') }}</span>
                            — {{ record.action }}
                            <span v-if="!record.effective" class="text-gray-500">(not yet effective)</span>
                            <span class="text-gray-500"> · {{ record.created_at }} · via {{ record.source }}</span>
                        </li>
                    </ul>
                </Card>
            </div>
        </div>
    </AdminLayout>
</template>
