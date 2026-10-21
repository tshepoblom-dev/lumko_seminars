<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import PortalPage from '@/Components/PortalPage.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    // From an emailed invitation link (/redeem?code=…): only pre-fills.
    code: { type: String, default: '' },
});

const form = useForm({ voucher_code: props.code });

function submit() {
    form.post(route('voucher.redeem.store'));
}
</script>

<template>
    <AttendeeLayout>
    <Head title="Redeem an invitation" />

    <PortalPage eyebrow="Invitation" title="Redeem your invitation code">
        <div class="max-w-prose space-y-3 text-[0.9375rem] text-ink-700">
            <p>If you've been invited as our guest, enter the code from your invitation. Your in-person ticket becomes complimentary and is confirmed straight away.</p>
            <p>Registered to attend online? Redeeming the code moves you to in person.</p>
        </div>

        <form class="mt-8 max-w-sm space-y-6" @submit.prevent="submit">
            <FormField id="redeem-voucher-code" label="Invitation code" hint="For example ABCD-EFGH-JKMN" :error="form.errors.voucher_code" v-slot="{ id, describedBy, invalid }">
                <TextInput
                    :id="id"
                    v-model="form.voucher_code"
                    type="text"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    class="tnum uppercase tracking-wide"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid"
                />
            </FormField>

            <Button type="submit" :disabled="form.processing">
                {{ form.processing ? 'Redeeming…' : 'Redeem code' }}
            </Button>
        </form>
    </PortalPage>
    </AttendeeLayout>
</template>
