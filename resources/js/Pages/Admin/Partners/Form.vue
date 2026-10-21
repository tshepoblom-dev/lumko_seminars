<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import InvitePortalModal from '@/Components/Admin/InvitePortalModal.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Create and edit a partner in the shared library.
const props = defineProps({
    partner: { type: Object, default: null },
    // Partner portal (edit only): { members, pending, contact_email, status, can_invite_owner }
    portal: { type: Object, default: null },
});

const page = usePage();
const isSuperAdmin = computed(() => !!page.props.auth?.user?.isSuperAdmin);

const form = useForm({
    name: props.partner?.name ?? '',
    website: props.partner?.website ?? '',
    description: props.partner?.description ?? '',
    contact_email: props.partner?.contact_email ?? '',
    logo: null,
    logo_alt: props.partner?.logo_alt ?? '',
    logo_approved: props.partner?.logo_approved ?? false,
    remove_logo: false,
    ...(props.partner ? { _method: 'put' } : { invite_owner: true }),
});

function submit() {
    form
        .transform((data) => (props.partner ? data : { ...data, invite_owner: data.invite_owner && !!data.contact_email.trim() }))
        .post(props.partner ? route('admin.partners.update', props.partner.slug) : route('admin.partners.store'), { forceFormData: true });
}

// ── Portal team ───────────────────────────────────────────────────────
// One modal for a new invitation (staff type the email) and for resending.
const invite = ref(null); // { action, askEmail, recipients, resend }

function openInvite() {
    invite.value = { action: route('admin.partners.invitations.store', props.partner.slug), askEmail: true, recipients: [], resend: false };
}

function openResend(invitation) {
    invite.value = {
        action: route('admin.partners.invitations.resend', [props.partner.slug, invitation.id]),
        askEmail: false,
        recipients: [{ name: 'them', email: invitation.email, role: invitation.role }],
        resend: true,
    };
}

function cancelInvitation(invitation) {
    if (!confirm(`Cancel the invitation to ${invitation.email}? Their link stops working.`)) return;
    router.delete(route('admin.partners.invitations.cancel', [props.partner.slug, invitation.id]), { preserveScroll: true });
}

const owners = computed(() => props.portal?.members.filter((m) => m.role === 'owner') ?? []);

function removeMember(member) {
    const lastOwner = member.role === 'owner' && owners.value.length === 1;
    const warning = lastOwner
        ? ` They are the only owner, so nobody at ${props.partner.name} will be able to invite or remove their team until you invite a new owner.`
        : '';
    if (!confirm(`Remove ${member.name} from ${props.partner.name}'s portal? Their account stays, but they lose access to this partner.${warning}`)) return;
    router.delete(route('admin.partners.members.remove', [props.partner.slug, member.id]), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ partner ? `Edit ${partner.name}` : 'New partner' }}</h2>
        </template>

        <Head :title="partner ? 'Edit partner' : 'New partner'" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl space-y-6 sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="partner-name">Name</label>
                        <input id="partner-name" v-model="form.name" required class="mt-1 w-full rounded border-gray-300" />
                        <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                        <p class="mt-1 text-xs text-gray-600">Shown to attendees, and in the consent sentence when they share their details.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="partner-website">Website</label>
                        <input id="partner-website" v-model="form.website" type="url" placeholder="https://" class="mt-1 w-full rounded border-gray-300" />
                        <p v-if="form.errors.website" class="text-sm text-red-600">{{ form.errors.website }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="partner-description">Description</label>
                        <textarea id="partner-description" v-model="form.description" rows="4" class="mt-1 w-full rounded border-gray-300"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="partner-email">Contact email</label>
                        <input
                            id="partner-email"
                            v-model="form.contact_email"
                            type="email"
                            autocomplete="off"
                            aria-describedby="partner-email-hint"
                            :aria-invalid="!!form.errors.contact_email"
                            class="mt-1 w-full rounded border-gray-300"
                        />
                        <p id="partner-email-hint" class="mt-1 text-xs text-gray-600">For our team, and where an owner invitation goes. Never shown to attendees.</p>
                        <p v-if="form.errors.contact_email" role="alert" class="text-sm text-red-600">{{ form.errors.contact_email }}</p>
                        <label v-if="!partner && form.contact_email.trim()" class="mt-2 flex items-start gap-2 text-sm text-gray-700">
                            <input v-model="form.invite_owner" type="checkbox" class="mt-0.5 rounded border-gray-300" />
                            <span>Invite their main contact as owner. We'll email them a link to create their account and open the partner portal.</span>
                        </label>
                    </div>

                    <div v-if="partner?.logo_url" class="flex items-center gap-4">
                        <img :src="partner.logo_url" :alt="partner.logo_alt ?? ''" class="max-h-14 w-auto max-w-[10rem] object-contain" />
                        <div class="space-y-1 text-sm">
                            <label class="flex items-center gap-2"><input v-model="form.logo_approved" type="checkbox" class="rounded border-gray-300" /> Approved (shown on the site)</label>
                            <label class="flex items-center gap-2"><input v-model="form.remove_logo" type="checkbox" class="rounded border-gray-300" /> Remove logo</label>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="partner-logo">{{ partner?.logo_url ? 'Replace logo' : 'Logo' }}</label>
                        <input id="partner-logo" type="file" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full max-w-full text-sm" @input="form.logo = $event.target.files[0]" />
                        <p v-if="form.errors.logo" class="text-sm text-red-600">{{ form.errors.logo }}</p>
                        <p class="mt-1 text-xs text-gray-600">A logo you upload here is approved straight away; one the partner uploads waits for approval.</p>
                    </div>
                    <div v-if="form.logo || partner?.logo_url">
                        <label class="block text-sm font-medium" for="partner-logo-alt">Logo description</label>
                        <input id="partner-logo-alt" v-model="form.logo_alt" class="mt-1 w-full rounded border-gray-300" />
                    </div>

                    <div class="flex items-center gap-4 pt-2">
                        <Button type="submit" :disabled="form.processing">{{ partner ? 'Save' : 'Add partner' }}</Button>
                        <Link :href="route('admin.partners.index')" class="text-sm text-gray-600 hover:underline">Cancel</Link>
                    </div>
                </form>

                <section v-if="partner && portal" id="portal" class="scroll-mt-6 rounded-lg bg-white p-6 shadow" aria-labelledby="partner-portal-title">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 id="partner-portal-title" class="font-semibold text-gray-800">Portal team</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                The people who sign in to {{ partner.name }}'s portal: leads, stand scanning, referrals, analytics, resources and tickets, depending on their tier on each event. Owners can invite and remove their own team.
                            </p>
                        </div>
                        <a v-if="isSuperAdmin" :href="route('partner.dashboard', partner.slug)" class="whitespace-nowrap text-sm font-medium text-brand hover:underline">Open portal</a>
                    </div>

                    <h4 class="mt-4 text-sm font-medium text-gray-800">People</h4>
                    <ul v-if="portal.members.length" class="mt-2 divide-y divide-gray-100 text-sm">
                        <li v-for="m in portal.members" :key="m.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="text-gray-800">
                                {{ m.name }} <span class="text-gray-600">· {{ m.email }}</span>
                            </span>
                            <span class="flex items-center gap-3">
                                <Badge :variant="m.role === 'owner' ? 'info' : 'neutral'">{{ m.role === 'owner' ? 'Owner' : 'Team member' }}</Badge>
                                <button type="button" class="text-red-700 hover:underline" @click="removeMember(m)">Remove<span class="sr-only"> {{ m.name }}</span></button>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-gray-600">Nobody has joined yet.</p>

                    <template v-if="portal.pending.length">
                        <h4 class="mt-4 text-sm font-medium text-gray-800">Invited, not joined yet</h4>
                        <ul class="mt-2 divide-y divide-gray-100 text-sm">
                            <li v-for="i in portal.pending" :key="i.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span class="text-gray-800">
                                    {{ i.email }} <span class="text-gray-600">· {{ i.role === 'owner' ? 'Owner' : 'Team member' }} · sent {{ i.invited_on }}, works until {{ i.expires_on }}</span>
                                </span>
                                <span class="flex gap-3">
                                    <button type="button" class="text-brand hover:underline" @click="openResend(i)">Resend<span class="sr-only"> to {{ i.email }}</span></button>
                                    <button type="button" class="text-red-700 hover:underline" @click="cancelInvitation(i)">Cancel<span class="sr-only"> the invitation to {{ i.email }}</span></button>
                                </span>
                            </li>
                        </ul>
                    </template>

                    <div class="mt-4">
                        <Button size="sm" variant="secondary" @click="openInvite">Invite someone</Button>
                    </div>

                    <p class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-600">Clients credited to this partner: {{ partner.conversions }}</p>
                </section>

                <InvitePortalModal
                    v-if="partner"
                    kind="partner"
                    :show="!!invite"
                    :action="invite?.action"
                    :ask-email="invite?.askEmail ?? false"
                    :partner-name="partner.name"
                    :default-role="portal?.members.length ? 'member' : 'owner'"
                    :recipients="invite?.recipients ?? []"
                    :resend="invite?.resend ?? false"
                    @close="invite = null"
                />
            </div>
        </div>
    </AdminLayout>
</template>
