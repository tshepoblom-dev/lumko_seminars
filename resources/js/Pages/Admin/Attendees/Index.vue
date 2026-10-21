<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const eventRoute = useEventRoute();

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'organisation', label: 'Organisation' },
    { key: 'attendance_mode', label: 'Mode' },
    { key: 'payment_status', label: 'Payment' },
    { key: 'checked_in', label: 'Checked in' },
    { key: 'account_set_up', label: 'Account' },
];

const paymentBadgeVariant = {
    approved: 'success',
    complimentary: 'success',
    pending: 'warning',
    under_review: 'warning',
    rejected: 'danger',
    cancelled: 'neutral',
};

const props = defineProps({
    event: Object,
    attendees: Object,
    filters: Object,
    industries: { type: Array, default: () => [] },
    // The event's interests and its own choice/yes-no questions (RegistrationForm::filterOptions).
    formFilters: { type: Object, default: () => ({ interests: [], questions: [] }) },
    total: { type: Number, default: 0 },
    notSetUpCount: { type: Number, default: 0 },
    // People with an account who aren't registered for this event:
    // { count, can_remind, rows: [{ id, name, email, signed_up_on, email_confirmed, reminded_on }] }
    accountsOnly: { type: Object, default: () => ({ count: 0, can_remind: false, rows: [] }) },
});

const confirmingRemindAll = ref(false);

function remind(account) {
    router.post(eventRoute('admin.attendees.accounts.remind', { user: account.id }), {}, { preserveScroll: true });
}

function remindAll() {
    router.post(eventRoute('admin.attendees.accounts.remind-all'), {}, {
        preserveScroll: true,
        onFinish: () => { confirmingRemindAll.value = false; },
    });
}

// Registrants who never used their "confirm email & set password" link.
const confirmingSetupLinks = ref(false);

function sendSetupLinks() {
    router.post(eventRoute('admin.attendees.setup-links'), {}, {
        preserveScroll: true,
        onFinish: () => { confirmingSetupLinks.value = false; },
    });
}

const form = reactive({
    search: props.filters.search ?? '',
    mode: props.filters.mode ?? '',
    payment_status: props.filters.payment_status ?? '',
    checked_in_only: !!props.filters.checked_in_only,
    networking_opt_in: props.filters.networking_opt_in ?? '',
    lumko_opt_in: props.filters.lumko_opt_in ?? '',
    wants_follow_up: !!props.filters.wants_follow_up,
    industry: props.filters.industry ?? '',
    interest: props.filters.interest ?? '',
    answers: Object.fromEntries(props.formFilters.questions.map((q) => [q.id, props.filters.answers?.[q.id] ?? ''])),
});

// The download and "Email these" match the list on screen: the filters
// already applied, not unsaved edits in the filter form.
const appliedFilters = computed(() =>
    Object.fromEntries(Object.entries(props.filters ?? {}).filter(([, v]) => v !== null && v !== '' && v !== false && v !== 'false')),
);
const exportUrl = computed(() => eventRoute('admin.attendees.export', appliedFilters.value));
const emailAllUrl = computed(() =>
    Object.keys(appliedFilters.value).length
        ? eventRoute('admin.communications.create', { mode: 'filtered', filters: appliedFilters.value })
        : eventRoute('admin.communications.create', { mode: 'all' }),
);
const emailOneUrl = (row) => eventRoute('admin.communications.create', { mode: 'selected', user_ids: [row.user_id] });

function applyFilters() {
    const answers = Object.fromEntries(Object.entries(form.answers).filter(([, v]) => v !== ''));
    router.get(eventRoute('admin.attendees.index'), { ...form, answers }, { preserveState: true, replace: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Attendees</h2>
        </template>

        <Head title="Attendees" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <form @submit.prevent="applyFilters" class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-white p-4 shadow">
                    <div>
                        <label class="block text-xs font-medium text-gray-600" for="admin-attendees-index-1">Search</label>
                        <input id="admin-attendees-index-1" v-model="form.search" type="text" placeholder="Name, email, organisation" class="mt-1 rounded border-gray-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600" for="admin-attendees-index-2">Mode</label>
                        <select id="admin-attendees-index-2" v-model="form.mode" class="mt-1 rounded border-gray-300 text-sm">
                            <option value="">Any</option>
                            <option value="online">Online</option>
                            <option value="in_person">In person</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600" for="admin-attendees-index-3">Payment status</label>
                        <select id="admin-attendees-index-3" v-model="form.payment_status" class="mt-1 rounded border-gray-300 text-sm">
                            <option value="">Any</option>
                            <option value="pending">Pending</option>
                            <option value="under_review">Under review</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-1.5 text-sm text-gray-700">
                        <input v-model="form.checked_in_only" type="checkbox" class="rounded border-gray-300" /> Checked in
                    </label>
                    <div>
                        <label class="block text-xs font-medium text-gray-600" for="admin-attendees-index-4">Networking opt-in</label>
                        <select id="admin-attendees-index-4" v-model="form.networking_opt_in" class="mt-1 rounded border-gray-300 text-sm">
                            <option value="">Any</option>
                            <option value="1">Opted in</option>
                            <option value="0">Not opted in</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600" for="admin-attendees-index-5">Lumko comms opt-in</label>
                        <select id="admin-attendees-index-5" v-model="form.lumko_opt_in" class="mt-1 rounded border-gray-300 text-sm">
                            <option value="">Any</option>
                            <option value="1">Opted in</option>
                            <option value="0">Not opted in</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600" for="admin-attendees-index-6">Industry</label>
                        <select id="admin-attendees-index-6" v-model="form.industry" class="mt-1 rounded border-gray-300 text-sm">
                            <option value="">Any</option>
                            <option v-for="i in industries" :key="i" :value="i">{{ i }}</option>
                        </select>
                    </div>
                    <div v-if="formFilters.interests.length">
                        <label class="block text-xs font-medium text-gray-600" for="admin-attendees-interest">Interested in</label>
                        <select id="admin-attendees-interest" v-model="form.interest" class="mt-1 rounded border-gray-300 text-sm">
                            <option value="">Any</option>
                            <option v-for="i in formFilters.interests" :key="i" :value="i">{{ i }}</option>
                        </select>
                    </div>
                    <div v-for="q in formFilters.questions" :key="q.id">
                        <label class="block max-w-[14rem] truncate text-xs font-medium text-gray-600" :for="`admin-attendees-q-${q.id}`" :title="q.label">{{ q.label }}</label>
                        <select :id="`admin-attendees-q-${q.id}`" v-model="form.answers[q.id]" class="mt-1 max-w-[14rem] rounded border-gray-300 text-sm">
                            <option value="">Any</option>
                            <option v-for="o in q.options" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-1.5 text-sm text-gray-700">
                        <input v-model="form.wants_follow_up" type="checkbox" class="rounded border-gray-300" /> Requested follow-up
                    </label>
                    <Button type="submit" size="sm">Apply</Button>
                    <span class="ml-auto flex flex-wrap gap-2">
                        <Button :href="emailAllUrl" variant="secondary" size="sm">Email {{ total === 1 ? 'this person' : `these ${total}` }}</Button>
                        <Button :href="exportUrl" native variant="secondary" size="sm">Export CSV</Button>
                    </span>
                </form>

                <div v-if="notSetUpCount" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p>
                        <strong>{{ notSetUpCount }}</strong>
                        {{ notSetUpCount === 1 ? "registrant hasn't" : "registrants haven't" }} set up their account yet.
                        They can't sign in until they use their setup link.
                    </p>
                    <Button size="sm" variant="secondary" @click="confirmingSetupLinks = true">Send setup links</Button>
                </div>

                <DataTable :columns="columns" :rows="attendees.data">
                    <template #cell-account_set_up="{ row }">
                        <Badge :variant="row.account_set_up ? 'success' : 'warning'">{{ row.account_set_up ? 'Set up' : 'Not set up' }}</Badge>
                    </template>
                    <template #cell-payment_status="{ row }">
                        <Badge v-if="row.payment_status" :variant="paymentBadgeVariant[row.payment_status_raw] ?? 'neutral'">
                            {{ row.payment_status }}
                        </Badge>
                        <span v-else>—</span>
                    </template>
                    <template #cell-checked_in="{ row }">
                        <Badge :variant="row.checked_in ? 'success' : 'neutral'">{{ row.checked_in ? 'Yes' : 'No' }}</Badge>
                    </template>
                    <template #actions="{ row }">
                        <span class="flex justify-end gap-3">
                            <Link :href="emailOneUrl(row)" class="text-brand hover:underline" :aria-label="`Email ${row.name}`">
                                Email
                            </Link>
                            <Link :href="eventRoute('admin.attendees.show', { registration: row.id })" class="text-brand hover:underline" :aria-label="`View ${row.name}`">
                                View
                            </Link>
                        </span>
                    </template>
                    <template #empty>
                        <EmptyState title="No attendees match these filters" description="Try clearing a filter or broadening your search." />
                    </template>
                </DataTable>

                <div v-if="attendees.links.length > 3" class="mt-4 flex flex-wrap gap-1">
                    <Link
                        v-for="(link, i) in attendees.links"
                        :key="i"
                        :href="link.url ?? '#'"
                        v-html="link.label"
                        preserve-scroll
                        :class="[
                            'rounded px-3 py-1 text-sm',
                            link.active ? 'bg-brand text-white' : 'bg-white text-gray-700 hover:bg-gray-100',
                            !link.url ? 'pointer-events-none opacity-50' : '',
                        ]"
                    />
                </div>

                <section v-if="accountsOnly.count" id="accounts-only" class="mt-8 rounded-lg bg-white p-6 shadow" aria-labelledby="accounts-only-title">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 id="accounts-only-title" class="font-semibold text-gray-800">Has an account, not registered yet ({{ accountsOnly.count }})</h3>
                            <p class="mt-1 max-w-prose text-sm text-gray-600">
                                These people have an account but haven't completed the registration form for this event, so they are not attendees and are not in the list above.
                            </p>
                        </div>
                        <Button v-if="accountsOnly.can_remind" size="sm" variant="secondary" @click="confirmingRemindAll = true">Remind all to register</Button>
                    </div>
                    <p v-if="!accountsOnly.can_remind" class="mt-3 text-sm text-gray-600">
                        Reminders can be sent while this is the event open for registration.
                    </p>

                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="border-b border-gray-200 text-left text-gray-600">
                                <tr>
                                    <th scope="col" class="py-2 pr-4 font-medium">Name</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Email</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Signed up</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Email address</th>
                                    <th scope="col" class="py-2 pr-4 font-medium">Reminder</th>
                                    <th scope="col" class="py-2 font-medium"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="a in accountsOnly.rows" :key="a.id">
                                    <td class="py-2 pr-4 font-medium text-gray-900">{{ a.name }}</td>
                                    <td class="py-2 pr-4 text-gray-700">{{ a.email }}</td>
                                    <td class="py-2 pr-4 text-gray-700">{{ a.signed_up_on }}</td>
                                    <td class="py-2 pr-4">
                                        <Badge :variant="a.email_confirmed ? 'success' : 'warning'">{{ a.email_confirmed ? 'Confirmed' : 'Not confirmed' }}</Badge>
                                    </td>
                                    <td class="py-2 pr-4 text-gray-700">{{ a.reminded_on ? `Sent ${a.reminded_on}` : 'Not sent' }}</td>
                                    <td class="whitespace-nowrap py-2 text-right">
                                        <button v-if="accountsOnly.can_remind" type="button" class="text-brand hover:underline" @click="remind(a)">
                                            {{ a.reminded_on ? 'Send again' : 'Send reminder' }}<span class="sr-only"> to {{ a.name }}</span>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="accountsOnly.count > accountsOnly.rows.length" class="mt-3 text-sm text-gray-600">
                        Showing the {{ accountsOnly.rows.length }} most recent of {{ accountsOnly.count }}. "Remind all" reaches everyone.
                    </p>
                </section>
            </div>
        </div>
        <Modal :show="confirmingRemindAll" title="Remind everyone to register" @close="confirmingRemindAll = false">
            <p class="text-sm text-gray-700">
                Email {{ accountsOnly.count === 1 ? 'the 1 person' : `the ${accountsOnly.count} people` }} who
                {{ accountsOnly.count === 1 ? 'has' : 'have' }} an account but no registration for {{ event.name }}, with a link to the registration form?
                Anyone reminded in the last 24 hours is skipped.
            </p>
            <template #footer>
                <Button variant="secondary" @click="confirmingRemindAll = false">Cancel</Button>
                <Button @click="remindAll">Send reminders</Button>
            </template>
        </Modal>
        <Modal :show="confirmingSetupLinks" title="Send setup links" @close="confirmingSetupLinks = false">
            <p class="text-sm text-gray-700">
                Email a fresh "confirm email &amp; set password" link to the {{ notSetUpCount }}
                {{ notSetUpCount === 1 ? "registrant who hasn't" : "registrants who haven't" }} set up their account?
                Any earlier links they received stop working.
            </p>
            <template #footer>
                <Button variant="secondary" @click="confirmingSetupLinks = false">Cancel</Button>
                <Button @click="sendSetupLinks">Send {{ notSetUpCount }} link{{ notSetUpCount === 1 ? '' : 's' }}</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
