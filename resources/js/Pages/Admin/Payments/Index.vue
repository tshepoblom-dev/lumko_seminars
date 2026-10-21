<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

defineProps({ payments: Array });
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Payments</h2>
        </template>

        <Head title="Payments" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-x-auto rounded-lg bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Attendee</th>
                                <th class="px-4 py-2 text-left">Organisation</th>
                                <th class="px-4 py-2 text-left">Amount</th>
                                <th class="px-4 py-2 text-left">Status</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="payment in payments" :key="payment.id">
                                <td class="px-4 py-2">{{ payment.attendee }}</td>
                                <td class="px-4 py-2">{{ payment.organisation }}</td>
                                <td class="px-4 py-2">{{ payment.complimentary ? 'Complimentary' : `R${payment.amount.toFixed(0)}` }}</td>
                                <td class="px-4 py-2 capitalize">{{ payment.status_label }}</td>
                                <td class="px-4 py-2 text-right">
                                    <Link :href="eventRoute('admin.payments.show', { payment: payment.id })" class="text-signal-700">
                                        Review
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
