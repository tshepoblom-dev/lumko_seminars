<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, useForm } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

const form = useForm({
    question: '',
    options: ['', ''],
    show_results_live: true,
});

function addOption() {
    form.options.push('');
}

function submit() {
    form.post(eventRoute('admin.live.polls.store'));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">New poll</h2>
        </template>

        <Head title="New poll" />

        <div class="py-12">
            <div class="mx-auto max-w-xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="admin-live-pollcreate-2">Question</label>
                        <input id="admin-live-pollcreate-2" v-model="form.question" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-live-pollcreate-3">Options</label>
                        <input id="admin-live-pollcreate-3"
                            v-for="(_, i) in form.options"
                            :key="i"
                            v-model="form.options[i]"
                            type="text"
                            class="mt-1 w-full rounded border-gray-300"
                        />
                        <button type="button" class="mt-2 text-sm text-signal-700" @click="addOption">+ Add option</button>
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="form.show_results_live" type="checkbox" class="mt-0.5 rounded border-gray-300" />
                        <span>
                            Show results to attendees while the poll is open
                            <span class="block text-xs text-gray-600">Otherwise they see the results when it closes.</span>
                        </span>
                    </label>
                    <button type="submit" class="rounded bg-ink-900 px-4 py-2 text-sm text-white" :disabled="form.processing">
                        Save as draft
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
