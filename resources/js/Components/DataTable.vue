<script setup>
defineProps({
    columns: { type: Array, required: true }, // [{ key, label }]
    rows: { type: Array, required: true },
    rowKey: { type: String, default: 'id' },
});
</script>

<template>
    <div class="overflow-hidden border border-rule bg-white">
        <!-- Desktop/tablet: a real table. -->
        <table class="hidden min-w-full text-sm sm:table">
            <thead class="border-b border-ink-900">
                <tr>
                    <th v-for="column in columns" :key="column.key" scope="col" class="eyebrow px-4 py-3 text-left text-ink-500">
                        {{ column.label }}
                    </th>
                    <th v-if="$slots.actions" scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-rule">
                <tr v-for="row in rows" :key="row[rowKey]" class="transition-colors hover:bg-paper">
                    <td v-for="column in columns" :key="column.key" class="px-4 py-3">
                        <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] }}</slot>
                    </td>
                    <td v-if="$slots.actions" class="px-4 py-3 text-right">
                        <slot name="actions" :row="row" />
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Mobile: one stacked card per row instead of a squeezed table. -->
        <ul class="divide-y divide-rule sm:hidden">
            <li v-for="row in rows" :key="row[rowKey]" class="p-4">
                <dl class="space-y-1">
                    <div v-for="column in columns" :key="column.key" class="flex justify-between gap-4 text-sm">
                        <dt class="text-ink-500">{{ column.label }}</dt>
                        <dd class="text-right">
                            <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] }}</slot>
                        </dd>
                    </div>
                </dl>
                <div v-if="$slots.actions" class="mt-2 text-right">
                    <slot name="actions" :row="row" />
                </div>
            </li>
        </ul>

        <div v-if="!rows.length" class="p-8 text-center text-ink-500">
            <slot name="empty">No data to show.</slot>
        </div>
    </div>
</template>
