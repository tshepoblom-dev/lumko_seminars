<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import Button from '@/Components/Button.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { longDate } from '@/lib/eventFormat';

// Shown once to a signed-in account that hasn't accepted the current
// Terms & Privacy Policy (EnsureLegalAccepted), then it returns them to
// where they were going.
const props = defineProps({
    facts: Object,
    updated: Boolean, // they accepted an earlier version
});

const form = useForm({ accept_legal: false });

function submit() {
    form.post(route('legal.accept.store'));
}
</script>

<template>
    <PublicLayout>
        <Head title="Terms & Privacy Policy" />

        <AuthFrame
            :title="updated ? 'We\'ve updated our terms' : 'Before you continue'"
            eyebrow="Terms & Privacy Policy"
        >
            <p class="text-[0.9375rem] leading-relaxed text-ink-700">
                {{ updated
                    ? `Our Terms & Conditions and Privacy Policy changed on ${longDate(facts.effective_date)}. Please read them and accept to keep using the platform.`
                    : 'Please read our Terms & Conditions and Privacy Policy and accept them to use the platform.' }}
            </p>

            <ul class="mt-6 divide-y divide-rule border-y border-rule">
                <li>
                    <a :href="route('legal.terms')" target="_blank" rel="noopener" class="group flex items-center justify-between gap-4 py-3.5 text-sm">
                        <span class="font-medium text-ink-900 group-hover:underline">Terms &amp; Conditions</span>
                        <span class="text-meta text-ink-500">Version {{ facts.versions.terms }}<span class="sr-only"> (opens in a new tab)</span> <span aria-hidden="true">&#8599;&#xFE0E;</span></span>
                    </a>
                </li>
                <li>
                    <a :href="route('legal.privacy')" target="_blank" rel="noopener" class="group flex items-center justify-between gap-4 py-3.5 text-sm">
                        <span class="font-medium text-ink-900 group-hover:underline">Privacy Policy</span>
                        <span class="text-meta text-ink-500">Version {{ facts.versions.privacy }}<span class="sr-only"> (opens in a new tab)</span> <span aria-hidden="true">&#8599;&#xFE0E;</span></span>
                    </a>
                </li>
            </ul>

            <form class="mt-6" @submit.prevent="submit">
                <label class="flex items-start gap-3 text-sm text-ink-800">
                    <Checkbox v-model:checked="form.accept_legal" name="accept_legal" class="mt-0.5" />
                    <span>I have read and accept the Terms &amp; Conditions and the Privacy Policy.</span>
                </label>
                <p v-if="form.errors.accept_legal" class="field-error" role="alert">{{ form.errors.accept_legal }}</p>

                <Button type="submit" class="mt-6 w-full" :disabled="form.processing" arrow>Accept and continue</Button>
            </form>

            <p class="mt-8 text-sm text-ink-600">
                Don't want to accept? You can
                <a :href="route('attendee.preferences.download')" class="link">download your information</a>,
                delete your account from the <Link :href="route('attendee.preferences.edit')" class="link">Account page</Link>, or
                <Link :href="route('logout')" method="post" as="button" class="link">sign out</Link>.
            </p>
        </AuthFrame>
    </PublicLayout>
</template>
