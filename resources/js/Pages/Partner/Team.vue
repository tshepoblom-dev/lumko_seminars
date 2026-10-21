<script setup>
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import PortalPage from '@/Components/PortalPage.vue';
import TextInput from '@/Components/TextInput.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { shortDate } from '@/lib/eventFormat';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    partner: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    members: { type: Array, default: () => [] },
    invitations: { type: Array, default: () => [] },
});

const form = useForm({ email: '', role: 'member' });

function invite() {
    form.post(route('partner.team.invite', props.partner.slug), { preserveScroll: true, onSuccess: () => form.reset() });
}

function remove(member) {
    if (!confirm(`Remove ${member.name} from ${props.partner.name}'s portal?`)) return;
    router.delete(route('partner.team.remove', [props.partner.slug, member.id]), { preserveScroll: true });
}

function revoke(invitation) {
    router.delete(route('partner.team.invitations.revoke', [props.partner.slug, invitation.id]), { preserveScroll: true });
}

const roleLabel = { owner: 'Owner', member: 'Team member' };
</script>

<template>
    <PartnerLayout :partner="partner">
        <Head :title="`Team · ${partner.name}`" />

        <PortalPage eyebrow="Partner portal" title="Your team">
            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                Everyone here can see your leads and scan tickets at your stand. Owners can also invite and remove people.
            </p>

            <ul class="mt-8 divide-y divide-rule border-y border-ink-900">
                <li v-for="m in members" :key="m.id" class="flex flex-wrap items-center justify-between gap-3 py-3.5 text-sm">
                    <span>
                        <span class="block font-medium text-ink-900">{{ m.name }}<template v-if="m.is_you"> (you)</template></span>
                        <span class="block text-meta text-ink-500">{{ m.email }} · {{ roleLabel[m.role] }}</span>
                    </span>
                    <button v-if="canManage && !m.is_you" type="button" class="link text-sm text-red-700" @click="remove(m)">Remove</button>
                </li>
            </ul>

            <template v-if="canManage">
                <section v-if="invitations.length" class="mt-10" aria-labelledby="pending-title">
                    <h2 id="pending-title" class="text-lg text-ink-900">Invited, not yet joined</h2>
                    <ul class="mt-3 divide-y divide-rule border-y border-rule">
                        <li v-for="i in invitations" :key="i.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <span>{{ i.email }} · {{ roleLabel[i.role] }} <span class="text-meta text-ink-500">(link works until {{ shortDate(i.expires_at) }})</span></span>
                            <button type="button" class="link text-sm" @click="revoke(i)">Cancel invitation</button>
                        </li>
                    </ul>
                </section>

                <form class="mt-10 grid gap-5 border-t border-rule pt-6 sm:grid-cols-[1fr_12rem_auto] sm:items-end" @submit.prevent="invite">
                    <FormField id="team-email" label="Invite someone by email" :error="form.errors.email" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" v-model="form.email" type="email" required autocomplete="off" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>
                    <FormField id="team-role" label="Role" :error="form.errors.role" v-slot="{ id, describedBy, invalid }">
                        <select :id="id" v-model="form.role" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid">
                            <option value="member">Team member</option>
                            <option value="owner">Owner</option>
                        </select>
                    </FormField>
                    <Button type="submit" :disabled="form.processing">Send invitation</Button>
                </form>
            </template>
        </PortalPage>
    </PartnerLayout>
</template>
