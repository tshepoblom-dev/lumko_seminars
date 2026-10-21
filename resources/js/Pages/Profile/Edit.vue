<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import PortalPage from '@/Components/PortalPage.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head, Link } from '@inertiajs/vue3';

// Staff see this inside the admin shell, attendees inside the portal.
const props = defineProps({
    profile: { type: Object, required: true },
    mustVerifyEmail: { type: Boolean, default: false },
    status: { type: String, default: null },
    isStaff: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Profile & password" />

    <AdminLayout v-if="isStaff">
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Profile &amp; password</h2>
        </template>

        <div class="mx-auto max-w-2xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg bg-white p-6 shadow">
                <UpdateProfileInformationForm :profile="profile" :must-verify-email="mustVerifyEmail" :status="status" />
            </div>
            <div class="rounded-lg bg-white p-6 shadow">
                <UpdatePasswordForm />
            </div>
        </div>
    </AdminLayout>

    <AttendeeLayout v-else>
        <PortalPage eyebrow="Your Lumko account" title="Profile & password">
            <p class="max-w-prose text-sm text-ink-600">
                Your sign-in details. Mobile, organisation and communication choices are on
                <Link :href="route('attendee.preferences.edit')" class="text-signal-700 underline">Account &amp; preferences</Link>.
            </p>

            <section class="mt-10 border-t border-ink-900 pt-6">
                <UpdateProfileInformationForm :profile="profile" :must-verify-email="mustVerifyEmail" :status="status" />
            </section>

            <section class="mt-12 border-t border-rule pt-6">
                <UpdatePasswordForm />
            </section>

            <section class="mt-12 border-t border-rule pt-6">
                <DeleteUserForm />
            </section>
        </PortalPage>
    </AttendeeLayout>
</template>
