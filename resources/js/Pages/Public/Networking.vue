<script setup>
import { Head, router, Link } from '@inertiajs/vue3';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import PortalPage from '@/Components/PortalPage.vue';
import Button from '@/Components/Button.vue';
import Badge from '@/Components/Badge.vue';

defineProps({
    iOptedIn: Boolean,
    directory: Array,
    incomingRequests: { type: Array, default: () => [] },
    myConnections: { type: Array, default: () => [] },
});

function sendRequest(registrationId) {
    router.post(route('networking.request', registrationId), {}, { preserveScroll: true });
}

function accept(connectionId) {
    router.post(route('networking.accept', connectionId), {}, { preserveScroll: true });
}

function decline(connectionId) {
    router.post(route('networking.decline', connectionId), {}, { preserveScroll: true });
}

function withdraw(connectionId) {
    router.delete(route('networking.withdraw', connectionId), { preserveScroll: true });
}

function remove(connectionId) {
    if (confirm('Remove this connection?')) {
        router.delete(route('networking.remove', connectionId), { preserveScroll: true });
    }
}
</script>

<template>
    <AttendeeLayout>
    <Head title="Networking" />

    <PortalPage eyebrow="Meet the room" title="Attendee networking">
        <p v-if="!iOptedIn" class="border border-rule border-t-2 border-t-amber-500 bg-white p-4 text-sm text-ink-800">
            Your profile isn't visible in the directory. You can still see connections you've already made below.
            <Link :href="route('attendee.preferences.edit')" class="link">Update your preferences</Link>
            to opt in and see who else is here.
        </p>

        <section v-if="incomingRequests.length" class="mt-10" aria-labelledby="requests-title">
            <h2 id="requests-title" class="border-b border-ink-900 pb-2 text-lg text-ink-900">Connection requests</h2>
            <ul>
                <li v-for="person in incomingRequests" :key="person.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-rule py-3 text-sm">
                    <span><span class="font-medium text-ink-900">{{ person.name }}</span> <span class="text-ink-500">({{ person.organisation }}) wants to connect</span></span>
                    <span class="flex gap-2">
                        <Button size="sm" @click="accept(person.id)">Accept</Button>
                        <Button size="sm" variant="secondary" @click="decline(person.id)">Decline</Button>
                    </span>
                </li>
            </ul>
        </section>

        <section v-if="myConnections.length" class="mt-10" aria-labelledby="connections-title">
            <h2 id="connections-title" class="border-b border-ink-900 pb-2 text-lg text-ink-900">My connections</h2>
            <ul>
                <li v-for="person in myConnections" :key="person.id" class="flex items-center justify-between gap-4 border-b border-rule py-3 text-sm">
                    <span>
                        <span class="block font-medium text-ink-900">{{ person.name }}</span>
                        <span class="block text-meta text-ink-500">{{ person.job_title }}, {{ person.organisation }}</span>
                    </span>
                    <button class="text-meta text-ink-500 underline underline-offset-4 hover:text-red-700" @click="remove(person.id)">Remove</button>
                </li>
            </ul>
        </section>

        <section class="mt-10" aria-labelledby="directory-title">
            <div class="flex items-baseline justify-between gap-4 border-b border-ink-900 pb-2">
                <h2 id="directory-title" class="text-lg text-ink-900">Directory</h2>
                <span class="tnum text-meta text-ink-500">{{ directory.length }} {{ directory.length === 1 ? 'person' : 'people' }}</span>
            </div>
            <ul>
                <li v-for="person in directory" :key="person.registration_id" class="grid gap-3 border-b border-rule py-5 sm:grid-cols-[1fr_auto] sm:items-start">
                    <div>
                        <p class="font-medium text-ink-900">{{ person.name }}</p>
                        <p class="text-sm text-ink-600">{{ person.job_title }}, {{ person.organisation }}</p>
                        <p class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                            <Badge :variant="person.attendance_mode === 'online' ? 'info' : 'neutral'">{{ person.attendance_mode_label }}</Badge>
                            <span v-if="person.interests?.length" class="text-meta text-ink-500">Interested in: {{ person.interests.join(', ') }}</span>
                        </p>
                    </div>
                    <div class="text-sm">
                        <Button
                            v-if="!person.connection"
                            size="sm"
                            variant="outline"
                            @click="sendRequest(person.registration_id)"
                        >
                            Connect
                        </Button>
                        <span v-else-if="person.connection.status === 'pending' && person.connection.i_requested" class="flex items-center gap-3">
                            <span class="text-ink-500">Request sent</span>
                            <button class="text-meta text-ink-500 underline underline-offset-4 hover:text-ink-900" @click="withdraw(person.connection.id)">Withdraw</button>
                        </span>
                        <Badge v-else-if="person.connection.status === 'accepted'" variant="success">Connected</Badge>
                        <span v-else-if="person.connection.status === 'declined'" class="text-ink-500">
                            Declined
                        </span>
                        <span v-else class="flex gap-2">
                            <Button size="sm" @click="accept(person.connection.id)">Accept</Button>
                            <Button size="sm" variant="secondary" @click="decline(person.connection.id)">Decline</Button>
                        </span>
                    </div>
                </li>
            </ul>

            <p v-if="!directory.length" class="py-6 text-sm text-ink-500">
                No other attendees have opted into networking yet.
            </p>
        </section>
    </PortalPage>
    </AttendeeLayout>
</template>
