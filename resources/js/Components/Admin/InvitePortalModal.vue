<script setup>
import Button from '@/Components/Button.vue';
import Modal from '@/Components/Modal.vue';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Confirms a speaker or partner portal invitation before it's emailed:
// one person (Invite / Resend), a whole line-up, or, with `askEmail`, a
// new partner team member whose address and role staff type here. Says
// who gets it, at which address, and what the portal is for. Posts to
// `action` on confirm.
const props = defineProps({
    show: { type: Boolean, default: false },
    kind: { type: String, default: 'speaker' }, // speaker | partner
    action: { type: String, default: null }, // the URL to POST to
    // [{ name, email, role? }]: who will be emailed (empty with askEmail)
    recipients: { type: Array, default: () => [] },
    // [name]: skipped (no contact email)
    skipped: { type: Array, default: () => [] },
    resend: { type: Boolean, default: false },
    // Partner team: staff type the email and pick owner / team member.
    askEmail: { type: Boolean, default: false },
    partnerName: { type: String, default: null },
    defaultRole: { type: String, default: 'member' }, // owner for a partner with no team yet
});

const emit = defineEmits(['close']);

const sending = ref(false);
const email = ref('');
const role = ref('member');
const error = ref(null);

watch(() => props.show, (open) => {
    if (open) {
        email.value = '';
        role.value = props.defaultRole;
        error.value = null;
    }
});

const ROLE_TEXT = {
    owner: 'as an owner (they can invite and remove their own team)',
    member: 'as a team member',
};

const single = computed(() => !props.askEmail && props.recipients.length === 1 && !props.skipped.length);
const title = computed(() => {
    const portal = props.kind === 'partner' ? 'partner portal' : 'speaker portal';
    if (props.askEmail) return `Invite someone to ${props.partnerName}'s portal`;
    if (single.value) return props.resend ? `Resend the ${portal} invitation` : `Invite to the ${portal}`;
    const what = props.kind === 'partner' ? 'partner' : 'speaker';
    return `Invite ${props.recipients.length} ${props.recipients.length === 1 ? what : `${what}s`} to the ${portal}`;
});

const purpose = computed(() => (props.kind === 'partner'
    ? 'In the portal they can see their leads (people who chose to share their details), scan tickets at their stand, use their referral link, see analytics, share resources, give away complimentary tickets and manage their team, depending on their tier.'
    : 'In the portal they can see their schedule, edit their profile, share slides and resources, test their camera and mic on the livestream, open their stage and see who asked to connect.'));

function confirm() {
    error.value = null;
    if (props.askEmail && !/^\S+@\S+\.\S+$/.test(email.value.trim())) {
        error.value = 'Enter an email address.';
        return;
    }
    router.post(props.action, props.askEmail ? { email: email.value.trim(), role: role.value } : {}, {
        preserveScroll: true,
        onStart: () => { sending.value = true; },
        onError: (errors) => { error.value = errors.email ?? errors.role ?? 'That didn\'t work. Try again.'; },
        onSuccess: () => emit('close'),
        onFinish: () => { sending.value = false; },
    });
}
</script>

<template>
    <Modal :show="show" :title="title" @close="emit('close')">
        <form id="invite-portal-form" class="space-y-3 text-sm text-gray-700" @submit.prevent="confirm">
            <template v-if="askEmail">
                <label class="block">
                    <span class="font-medium text-gray-800">Their email</span>
                    <input v-model="email" type="email" required autocomplete="off" class="mt-1 w-full rounded border-gray-300" />
                </label>
                <label class="block">
                    <span class="font-medium text-gray-800">Role</span>
                    <select v-model="role" class="mt-1 w-full rounded border-gray-300">
                        <option value="owner">Owner (can invite and remove their own team)</option>
                        <option value="member">Team member</option>
                    </select>
                </label>
                <p>We'll email them a link to create their account and join {{ partnerName }}'s portal {{ ROLE_TEXT[role] }}.</p>
            </template>
            <p v-else-if="single">
                We'll email <strong>{{ recipients[0]?.name }}</strong> at <strong>{{ recipients[0]?.email }}</strong>
                a link to create their account<template v-if="recipients[0]?.role"> {{ ROLE_TEXT[recipients[0].role] }}</template>.
            </p>
            <template v-else>
                <p>We'll email each of these a link to create their account:</p>
                <ul class="max-h-48 list-disc space-y-0.5 overflow-y-auto pl-5">
                    <li v-for="r in recipients" :key="r.email">{{ r.name }} <span class="text-gray-600">({{ r.email }})</span></li>
                </ul>
                <p v-if="kind === 'partner'">Each is invited as an <strong>owner</strong>, so they can invite the rest of their team.</p>
                <p v-if="skipped.length" class="rounded bg-amber-50 p-2 text-amber-900">
                    Skipped, no contact email: {{ skipped.join(', ') }}. Add one on their page to invite them.
                </p>
            </template>
            <p>{{ purpose }}</p>
            <p class="text-gray-600">
                The link works once, for 14 days.<template v-if="resend"> The earlier invitation stops working.</template>
            </p>
            <p v-if="error" class="text-red-700" role="alert">{{ error }}</p>
        </form>
        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="invite-portal-form" :disabled="sending || (!askEmail && !recipients.length)">
                {{ sending ? 'Sending…' : (resend ? 'Resend invitation' : (!single && !askEmail && recipients.length > 1 ? 'Send invitations' : 'Send invitation')) }}
            </Button>
        </template>
    </Modal>
</template>
