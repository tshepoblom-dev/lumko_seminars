<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import InvitePortalModal from '@/Components/Admin/InvitePortalModal.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    event: Object,
    features: { type: Array, default: () => [] }, // [{ value, label }]
    tiers: { type: Array, default: () => [] },
    partners: { type: Array, default: () => [] },
    library: { type: Array, default: () => [] },
    referrals: { type: Array, default: () => [] },
});

const eventRoute = useEventRoute();
const featureLabel = (value) => props.features.find((f) => f.value === value)?.label ?? value;
const rand = (cents) => `R${(Number(cents ?? 0) / 100).toLocaleString('en-ZA', { maximumFractionDigits: 0 })}`;

// ── Tiers ─────────────────────────────────────────────────────────────
const editingTier = ref(null); // null | 'new' | tier
const tierForm = useForm({ name: '', features: [], comp_tickets: 0, sort_order: 0 });

function openTier(tier = null) {
    editingTier.value = tier ?? 'new';
    tierForm.clearErrors();
    tierForm.name = tier?.name ?? '';
    // A new tier starts with what most partners get; referrals on by default.
    tierForm.features = tier ? [...tier.features] : ['listing', 'profile_page', 'leads_connect', 'referrals'];
    tierForm.comp_tickets = tier?.comp_tickets ?? 0;
    tierForm.sort_order = tier?.sort_order ?? 0;
}

function saveTier() {
    const options = { preserveScroll: true, onSuccess: () => { editingTier.value = null; } };
    if (editingTier.value === 'new') tierForm.post(eventRoute('admin.events.partner-tiers.store'), options);
    else tierForm.put(eventRoute('admin.events.partner-tiers.update', { partnerTier: editingTier.value.id }), options);
}

function deleteTier(tier) {
    if (!confirm(`Remove the ${tier.name} tier? Partners on it lose its features until you choose another tier.`)) return;
    router.delete(eventRoute('admin.events.partner-tiers.destroy', { partnerTier: tier.id }), { preserveScroll: true });
}

// ── Partners on the event ──────────────────────────────────────────────
const attachForm = useForm({ partner_id: null, partner_tier_id: null });

function attach() {
    attachForm.post(eventRoute('admin.events.partners.attach'), { preserveScroll: true, onSuccess: () => attachForm.reset() });
}

function update(partner, changes) {
    router.patch(eventRoute('admin.events.partners.update', { eventPartner: partner.id }), changes, { preserveScroll: true });
}

function detach(partner) {
    if (!confirm(`Remove ${partner.name} from this event?`)) return;
    router.delete(eventRoute('admin.events.partners.detach', { eventPartner: partner.id }), { preserveScroll: true });
}

const editing = ref(null);
const editForm = useForm({ stand_label: '', blurb: '' });

function openEdit(partner) {
    editing.value = partner;
    editForm.stand_label = partner.stand_label ?? '';
    editForm.blurb = partner.blurb ?? '';
}

function saveEdit() {
    editForm.patch(eventRoute('admin.events.partners.update', { eventPartner: editing.value.id }), {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
    });
}

// ── Partner portal: team and invitations ───────────────────────────────
const page = usePage();
const isSuperAdmin = computed(() => !!page.props.auth?.user?.isSuperAdmin);

const portalLabel = (p) => {
    if (p.status === 'active') {
        const people = `${p.members.length} ${p.members.length === 1 ? 'person' : 'people'}`;
        return p.pending.length ? `${people} · ${p.pending.length} invited` : people;
    }
    return p.status === 'invited' ? 'Invited, not joined yet' : 'No account yet';
};
const portalVariant = (p) => ({ active: 'success', invited: 'warning', none: 'neutral' })[p.status];

// One modal for a single invitation (staff type the email) and for
// inviting owners in bulk.
const invite = ref(null); // { action, askEmail, partnerName, defaultRole, recipients, skipped }

function openInvite(partner) {
    invite.value = {
        action: route('admin.partners.invitations.store', partner.slug),
        askEmail: true,
        partnerName: partner.name,
        defaultRole: partner.portal.members.length ? 'member' : 'owner',
        recipients: [],
        skipped: [],
    };
}

const withoutAccount = computed(() => props.partners.filter((p) => p.portal.status === 'none'));
const ownerInvitable = computed(() => withoutAccount.value.filter((p) => p.portal.can_invite_owner));

function inviteOwners() {
    invite.value = {
        action: eventRoute('admin.events.partners.invite-owners'),
        askEmail: false,
        recipients: ownerInvitable.value.map((p) => ({ name: p.name, email: p.portal.contact_email, role: 'owner' })),
        skipped: withoutAccount.value.filter((p) => !p.portal.can_invite_owner).map((p) => p.name),
    };
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Partners</h2>
        </template>

        <Head title="Event partners" />

        <div class="space-y-6 py-6">
            <p class="max-w-prose text-sm text-gray-600">
                Partners come from the shared <Link :href="route('admin.partners.index')" class="text-brand underline">partner library</Link>.
                Each partner is on one of this event's tiers, which decides what they get. Partners only ever see attendees who chose to share their details with them.
            </p>

            <!-- Tiers -->
            <Card title="Tiers">
                <template #actions>
                    <Button size="sm" variant="secondary" @click="openTier()">New tier</Button>
                </template>
                <p v-if="!tiers.length" class="text-sm text-gray-600">No tiers yet. Create one (for example Gold, Silver, Supporter) before adding partners.</p>
                <ul class="divide-y divide-gray-100">
                    <li v-for="tier in tiers" :key="tier.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                        <div>
                            <p class="font-medium text-gray-900">{{ tier.name }} <span class="font-normal text-gray-600">· {{ tier.partners_count }} {{ tier.partners_count === 1 ? 'partner' : 'partners' }}</span></p>
                            <p class="mt-1 text-gray-600">
                                {{ tier.features.length ? tier.features.map(featureLabel).join(', ') : 'No features' }}<template v-if="tier.features.includes('comp_tickets')"> ({{ tier.comp_tickets }})</template>
                            </p>
                        </div>
                        <div class="flex gap-3">
                            <button type="button" class="text-brand hover:underline" @click="openTier(tier)">Edit</button>
                            <button type="button" class="text-red-600 hover:underline" @click="deleteTier(tier)">Remove</button>
                        </div>
                    </li>
                </ul>
            </Card>

            <!-- Partners -->
            <Card title="Partners on this event">
                <template v-if="withoutAccount.length" #actions>
                    <Button size="sm" variant="secondary" @click="inviteOwners">
                        Invite owners for partners without an account ({{ ownerInvitable.length }})
                    </Button>
                </template>
                <form v-if="library.length" class="mb-5 flex flex-wrap items-end gap-3 text-sm" @submit.prevent="attach">
                    <label class="block">
                        <span class="text-gray-600">Partner</span>
                        <select v-model="attachForm.partner_id" required class="mt-1 block w-64 rounded border-gray-300">
                            <option :value="null" disabled>Choose…</option>
                            <option v-for="p in library" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-gray-600">Tier</span>
                        <select v-model="attachForm.partner_tier_id" class="mt-1 block w-48 rounded border-gray-300">
                            <option :value="null">Not set yet</option>
                            <option v-for="t in tiers" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </label>
                    <Button type="submit" size="sm" :disabled="attachForm.processing || !attachForm.partner_id">Add to event</Button>
                    <p v-if="attachForm.errors.partner_id" class="w-full text-red-700">{{ attachForm.errors.partner_id }}</p>
                </form>
                <p v-else class="mb-5 text-sm text-gray-600">
                    Every partner in the library is on this event. <Link :href="route('admin.partners.create')" class="text-brand underline">Add a new partner</Link>.
                </p>

                <p v-if="!partners.length" class="text-sm text-gray-600">No partners on this event yet.</p>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-left text-gray-600">
                            <tr>
                                <th scope="col" class="py-2 pr-4 font-medium">Partner</th>
                                <th scope="col" class="py-2 pr-4 font-medium">Tier</th>
                                <th scope="col" class="py-2 pr-4 font-medium">On the site</th>
                                <th scope="col" class="py-2 pr-4 font-medium">Portal</th>
                                <th scope="col" class="py-2 pr-4 font-medium">Leads</th>
                                <th scope="col" class="py-2 font-medium"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="p in partners" :key="p.id" class="align-top">
                                <td class="py-3 pr-4">
                                    <span class="font-medium text-gray-900">{{ p.name }}</span>
                                    <span v-if="p.stand_label" class="block text-xs text-gray-600">Stand: {{ p.stand_label }}</span>
                                    <span v-if="p.logo_url && !p.logo_approved" class="block text-xs text-amber-800">Logo awaiting approval</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <label :for="`tier-${p.id}`" class="sr-only">Tier for {{ p.name }}</label>
                                    <select :id="`tier-${p.id}`" :value="p.tier_id" class="rounded border-gray-300 text-sm" @change="update(p, { partner_tier_id: $event.target.value ? Number($event.target.value) : null })">
                                        <option :value="null">Not set</option>
                                        <option v-for="t in tiers" :key="t.id" :value="t.id">{{ t.name }}</option>
                                    </select>
                                </td>
                                <td class="py-3 pr-4">
                                    <Badge :variant="p.is_published ? 'success' : 'neutral'">{{ p.is_published ? 'Published' : 'Hidden' }}</Badge>
                                    <button type="button" class="block text-xs text-brand hover:underline" @click="update(p, { is_published: !p.is_published })">
                                        {{ p.is_published ? 'Hide' : 'Publish' }}
                                    </button>
                                </td>
                                <td class="py-3 pr-4 text-gray-700">
                                    <Badge :variant="portalVariant(p.portal)">{{ portalLabel(p.portal) }}</Badge>
                                    <span class="mt-1 flex flex-wrap gap-x-3 text-xs">
                                        <button type="button" class="text-brand hover:underline" @click="openInvite(p)">Invite<span class="sr-only"> someone from {{ p.name }}</span></button>
                                        <Link :href="`${route('admin.partners.edit', p.slug)}#portal`" class="text-brand hover:underline">Team<span class="sr-only"> for {{ p.name }}</span></Link>
                                        <a v-if="isSuperAdmin" :href="route('partner.dashboard', p.slug)" class="text-brand hover:underline">Open portal<span class="sr-only"> for {{ p.name }}</span></a>
                                    </span>
                                </td>
                                <td class="tnum py-3 pr-4 text-gray-700">{{ p.leads }}</td>
                                <td class="whitespace-nowrap py-3 text-right">
                                    <button type="button" class="text-brand hover:underline" @click="openEdit(p)">Details</button>
                                    <button type="button" class="ml-3 text-red-600 hover:underline" @click="detach(p)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <!-- Referrals -->
            <Card title="Referrals">
                <p class="text-sm text-gray-600">
                    People who named a partner under “How did you hear about us?”, and how many you've marked as clients (on each attendee's page). Use this to settle partner rewards.
                </p>
                <p v-if="!referrals.length" class="mt-3 text-sm text-gray-600">No referrals yet.</p>
                <table v-else class="mt-3 min-w-full text-sm">
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
            </Card>
        </div>

        <Modal :show="!!editingTier" :title="editingTier === 'new' ? 'New tier' : `Edit ${editingTier?.name}`" @close="editingTier = null">
            <form id="tier-form" class="space-y-4 text-sm" @submit.prevent="saveTier">
                <label class="block">
                    <span class="font-medium text-gray-800">Name</span>
                    <input v-model="tierForm.name" required maxlength="80" class="mt-1 w-full rounded border-gray-300" />
                    <span v-if="tierForm.errors.name" class="text-red-700">{{ tierForm.errors.name }}</span>
                </label>
                <fieldset>
                    <legend class="font-medium text-gray-800">What partners on this tier get</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label v-for="f in features" :key="f.value" class="flex items-center gap-2">
                            <input v-model="tierForm.features" type="checkbox" :value="f.value" class="rounded border-gray-300" />
                            {{ f.label }}
                        </label>
                    </div>
                </fieldset>
                <label v-if="tierForm.features.includes('comp_tickets')" class="block">
                    <span class="font-medium text-gray-800">Complimentary tickets per partner</span>
                    <input v-model.number="tierForm.comp_tickets" type="number" min="0" max="500" class="mt-1 w-32 rounded border-gray-300" />
                </label>
                <label v-if="editingTier !== 'new'" class="block">
                    <span class="font-medium text-gray-800">Order (lower shows first)</span>
                    <input v-model.number="tierForm.sort_order" type="number" min="0" class="mt-1 w-32 rounded border-gray-300" />
                </label>
            </form>
            <template #footer>
                <Button variant="secondary" @click="editingTier = null">Cancel</Button>
                <Button type="submit" form="tier-form" :disabled="tierForm.processing">Save</Button>
            </template>
        </Modal>

        <Modal :show="!!editing" :title="`${editing?.name} on this event`" @close="editing = null">
            <form id="ep-form" class="space-y-4 text-sm" @submit.prevent="saveEdit">
                <label class="block">
                    <span class="font-medium text-gray-800">Stand or table</span>
                    <input v-model="editForm.stand_label" maxlength="80" class="mt-1 w-full rounded border-gray-300" placeholder="e.g. Stand 4, foyer" />
                </label>
                <label class="block">
                    <span class="font-medium text-gray-800">Message to attendees</span>
                    <textarea v-model="editForm.blurb" rows="3" maxlength="2000" class="mt-1 w-full rounded border-gray-300"></textarea>
                    <span class="text-xs text-gray-600">The partner can edit this from their portal too.</span>
                </label>
            </form>
            <template #footer>
                <Button variant="secondary" @click="editing = null">Cancel</Button>
                <Button type="submit" form="ep-form" :disabled="editForm.processing">Save</Button>
            </template>
        </Modal>

        <InvitePortalModal
            kind="partner"
            :show="!!invite"
            :action="invite?.action"
            :ask-email="invite?.askEmail ?? false"
            :partner-name="invite?.partnerName"
            :default-role="invite?.defaultRole ?? 'member'"
            :recipients="invite?.recipients ?? []"
            :skipped="invite?.skipped ?? []"
            @close="invite = null"
        />
    </AdminLayout>
</template>
