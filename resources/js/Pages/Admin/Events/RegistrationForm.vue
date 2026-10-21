<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Event → Registration form: the questions on the two editable steps of
// this event's /attend form (steps 4 and 5).
const props = defineProps({
    event: Object,
    types: { type: Array, default: () => [] },
    pageTitles: { type: Array, default: () => [] },
    questions: { type: Array, default: () => [] },
    hasAnswers: { type: Boolean, default: false },
    copySources: { type: Array, default: () => [] },
});

const eventRoute = useEventRoute();
const typeLabel = (value) => props.types.find((t) => t.value === value)?.label ?? value;
const isChoice = (type) => type === 'single_choice' || type === 'multi_choice';
// Step 4 (the standard questions) and step 5 (left out of the form until
// it has a question).
const pages = computed(() => [1, 2].map((page) => ({
    page,
    title: props.pageTitles[page - 1],
    questions: props.questions.filter((q) => q.page === page),
})));

// ── Step titles ───────────────────────────────────────────────────────
const titlesForm = useForm({ titles: [props.pageTitles[0], props.pageTitles[1]] });
function saveTitles() {
    titlesForm.put(eventRoute('admin.events.registration-form.titles'), { preserveScroll: true });
}

// ── Question editor ───────────────────────────────────────────────────
const editing = ref(null); // null | 'new' | question
const questionForm = useForm({
    type: 'single_choice',
    page: 1,
    label: '',
    help_text: '',
    required: false,
    options: [],
    settings: {},
});

function blankSettings() {
    return { display: '', max_selections: null, min: null, max: null, max_kb: 5120 };
}

function openEditor(question = null, page = 1) {
    editing.value = question ?? 'new';
    questionForm.clearErrors();
    questionForm.type = question?.type ?? 'single_choice';
    questionForm.page = question?.page ?? page;
    questionForm.label = question?.label ?? '';
    questionForm.help_text = question?.help_text ?? '';
    questionForm.required = question?.required ?? false;
    questionForm.options = question ? question.options.map((o) => ({ ...o })) : [{ id: null, label: '', hidden: false }, { id: null, label: '', hidden: false }];
    questionForm.settings = { ...blankSettings(), ...(question?.settings ?? {}) };
}

const isBuiltIn = computed(() => editing.value && editing.value !== 'new' && !!editing.value.key);
const typeLocked = computed(() => isBuiltIn.value || (editing.value !== 'new' && editing.value?.answers_count > 0));

function addOption() {
    questionForm.options.push({ id: null, label: '', hidden: false });
}

function moveOption(i, delta) {
    const j = i + delta;
    if (j < 0 || j >= questionForm.options.length) return;
    const list = questionForm.options;
    [list[i], list[j]] = [list[j], list[i]];
}

function removeOption(i) {
    // A saved option may have answers: it's hidden, not deleted.
    const option = questionForm.options[i];
    if (option.id) option.hidden = true;
    else questionForm.options.splice(i, 1);
}

function saveQuestion() {
    const payload = (data) => ({
        ...data,
        type: isBuiltIn.value ? undefined : data.type,
        options: isChoice(data.type) ? data.options.filter((o) => o.label.trim() !== '') : [],
    });
    const options = { preserveScroll: true, onSuccess: () => { editing.value = null; } };

    if (editing.value === 'new') {
        questionForm.transform(payload).post(eventRoute('admin.events.registration-form.questions.store'), options);
    } else {
        questionForm.transform(payload).put(eventRoute('admin.events.registration-form.questions.update', { registrationQuestion: editing.value.id }), options);
    }
}

// ── List actions ──────────────────────────────────────────────────────
function move(question, direction) {
    router.post(eventRoute('admin.events.registration-form.questions.move', { registrationQuestion: question.id }), { direction }, { preserveScroll: true });
}

function toggle(question) {
    router.post(eventRoute('admin.events.registration-form.questions.toggle', { registrationQuestion: question.id }), {}, { preserveScroll: true });
}

function destroy(question) {
    if (!confirm(`Delete “${question.label}”?`)) return;
    router.delete(eventRoute('admin.events.registration-form.questions.destroy', { registrationQuestion: question.id }), { preserveScroll: true });
}

const copyForm = useForm({ source_event_id: null });
function copy() {
    if (!confirm('Replace this event’s questions with a copy of the other event’s?')) return;
    copyForm.post(eventRoute('admin.events.registration-form.copy'), { preserveScroll: true });
}

const visibleOptions = (q) => q.options.filter((o) => !o.hidden);
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Registration form</h2>
                <Button size="sm" variant="secondary" :href="eventRoute('admin.events.registration-form.preview')" external>Preview the form</Button>
            </div>
        </template>

        <Head title="Registration form" />

        <div class="space-y-6 py-6">
            <p class="max-w-prose text-sm text-gray-600">
                Steps 4 and 5 of this event's registration form are yours to shape. Step 4 holds the standard questions; step 5 is
                for anything else and is left out of the form until it has a question. The other steps (details, organisation,
                attendance, networking and staying in touch) are the same for every event. You can change questions after people
                have registered: their answers keep the wording they saw.
            </p>
            <p class="max-w-prose text-sm text-gray-600">
                “How did you hear about us?” on step 2 lists every partner published on this event, with their logo.
                <Link :href="eventRoute('admin.events.partners.index')" class="text-brand underline">Manage partners</Link>
            </p>

            <Card title="Step titles">
                <form class="grid gap-3 text-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end" @submit.prevent="saveTitles">
                    <label v-for="(t, i) in titlesForm.titles" :key="i" class="block">
                        <span class="text-gray-600">Step {{ i + 4 }}</span>
                        <input v-model="titlesForm.titles[i]" required maxlength="200" class="mt-1 w-full rounded border-gray-300" />
                    </label>
                    <Button type="submit" size="sm" :disabled="titlesForm.processing">Save titles</Button>
                </form>
                <p class="mt-2 text-xs text-gray-600">A step with no questions switched on is left out of the form.</p>
            </Card>

            <Card v-for="p in pages" :key="p.page" :title="`Step ${p.page + 3}: ${p.title}`">
                <template #actions>
                    <Button size="sm" variant="secondary" @click="openEditor(null, p.page)">Add a question</Button>
                </template>
                <p v-if="!p.questions.length" class="text-sm text-gray-600">No questions yet. This step is left out of the form until you add one.</p>
                <ol class="divide-y divide-gray-100">
                    <li v-for="(q, i) in p.questions" :key="q.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm" :class="q.is_active ? '' : 'opacity-70'">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900">
                                {{ q.label }}<span v-if="q.required" class="text-red-700" aria-label="required"> *</span>
                            </p>
                            <p class="mt-0.5 text-gray-600">
                                {{ typeLabel(q.type) }}<template v-if="q.key"> · standard question</template>
                                · {{ q.answers_count }} {{ q.answers_count === 1 ? 'answer' : 'answers' }}
                            </p>
                            <p v-if="isChoice(q.type)" class="mt-1 text-gray-700">{{ visibleOptions(q).map((o) => o.label).join(' · ') }}</p>
                            <p class="mt-1"><Badge :variant="q.is_active ? 'success' : 'neutral'">{{ q.is_active ? 'On the form' : 'Switched off' }}</Badge></p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" class="text-brand hover:underline disabled:opacity-40" :disabled="i === 0" :aria-label="`Move ${q.label} up`" @click="move(q, 'up')">Up</button>
                            <button type="button" class="text-brand hover:underline disabled:opacity-40" :disabled="i === p.questions.length - 1" :aria-label="`Move ${q.label} down`" @click="move(q, 'down')">Down</button>
                            <button type="button" class="text-brand hover:underline" @click="openEditor(q)">Edit</button>
                            <button type="button" class="text-brand hover:underline" @click="toggle(q)">{{ q.is_active ? 'Switch off' : 'Switch on' }}</button>
                            <button v-if="!q.key && !q.answers_count" type="button" class="text-red-600 hover:underline" @click="destroy(q)">Delete</button>
                        </div>
                    </li>
                </ol>
            </Card>

            <Card v-if="copySources.length && !hasAnswers" title="Start from another event">
                <form class="flex flex-wrap items-end gap-3 text-sm" @submit.prevent="copy">
                    <label class="block">
                        <span class="text-gray-600">Copy the questions from</span>
                        <select v-model="copyForm.source_event_id" required class="mt-1 block w-72 rounded border-gray-300">
                            <option :value="null" disabled>Choose an event…</option>
                            <option v-for="e in copySources" :key="e.id" :value="e.id">{{ e.name }}</option>
                        </select>
                    </label>
                    <Button type="submit" size="sm" variant="secondary" :disabled="copyForm.processing || !copyForm.source_event_id">Copy</Button>
                </form>
                <p class="mt-2 text-xs text-gray-600">This replaces the questions above. It's only possible before anyone has registered.</p>
            </Card>
        </div>

        <Modal :show="!!editing" :title="editing === 'new' ? 'Add a question' : 'Edit question'" @close="editing = null">
            <form id="question-form" class="space-y-4 text-sm" @submit.prevent="saveQuestion">
                <p class="rounded bg-amber-50 p-3 text-xs text-amber-900">
                    Only ask what you need for this event. Don't ask for ID numbers, health, religion or other special personal information.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="font-medium text-gray-800">Type of answer</span>
                        <select v-model="questionForm.type" :disabled="typeLocked" class="mt-1 w-full rounded border-gray-300">
                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                        <span v-if="typeLocked" class="text-xs text-gray-600">{{ isBuiltIn ? 'Standard questions keep their type.' : 'Fixed once people have answered.' }}</span>
                        <span v-if="questionForm.errors.type" class="block text-red-700">{{ questionForm.errors.type }}</span>
                    </label>
                    <label class="block">
                        <span class="font-medium text-gray-800">Step</span>
                        <select v-model.number="questionForm.page" class="mt-1 w-full rounded border-gray-300">
                            <option :value="1">Step 4: {{ pageTitles[0] }}</option>
                            <option :value="2">Step 5: {{ pageTitles[1] }}</option>
                        </select>
                    </label>
                </div>

                <label class="block">
                    <span class="font-medium text-gray-800">Question</span>
                    <input v-model="questionForm.label" required maxlength="500" class="mt-1 w-full rounded border-gray-300" />
                    <span v-if="questionForm.errors.label" class="text-red-700">{{ questionForm.errors.label }}</span>
                </label>
                <label class="block">
                    <span class="font-medium text-gray-800">Help text (optional)</span>
                    <input v-model="questionForm.help_text" maxlength="1000" class="mt-1 w-full rounded border-gray-300" />
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="questionForm.required" type="checkbox" class="rounded border-gray-300" />
                    People must answer this question
                </label>

                <fieldset v-if="isChoice(questionForm.type)">
                    <legend class="font-medium text-gray-800">Options</legend>
                    <ul class="mt-2 space-y-2">
                        <li v-for="(o, i) in questionForm.options" :key="i" class="flex items-center gap-2" :class="o.hidden ? 'opacity-60' : ''">
                            <label :for="`option-${i}`" class="sr-only">Option {{ i + 1 }}</label>
                            <input :id="`option-${i}`" v-model="o.label" maxlength="200" class="w-full rounded border-gray-300" />
                            <button type="button" class="text-xs text-brand hover:underline" :aria-label="`Move option ${i + 1} up`" @click="moveOption(i, -1)">Up</button>
                            <button type="button" class="text-xs text-brand hover:underline" :aria-label="`Move option ${i + 1} down`" @click="moveOption(i, 1)">Down</button>
                            <button v-if="o.hidden" type="button" class="whitespace-nowrap text-xs text-brand hover:underline" @click="o.hidden = false">Show</button>
                            <button v-else type="button" class="whitespace-nowrap text-xs text-red-600 hover:underline" @click="removeOption(i)">{{ o.id ? 'Hide' : 'Remove' }}</button>
                        </li>
                    </ul>
                    <button type="button" class="mt-2 text-brand hover:underline" @click="addOption">Add an option</button>
                    <p class="mt-1 text-xs text-gray-600">Hidden options aren't offered to new registrants; answers that used them are kept.</p>
                    <span v-if="questionForm.errors.options" class="block text-red-700">{{ questionForm.errors.options }}</span>
                </fieldset>

                <label v-if="questionForm.type === 'single_choice'" class="block">
                    <span class="font-medium text-gray-800">Show as</span>
                    <select v-model="questionForm.settings.display" class="mt-1 w-full rounded border-gray-300">
                        <option value="">Automatic (a list, or a dropdown for more than 6 options)</option>
                        <option value="radio">A list of options</option>
                        <option value="dropdown">A dropdown</option>
                    </select>
                </label>
                <label v-if="questionForm.type === 'multi_choice'" class="block">
                    <span class="font-medium text-gray-800">Most options someone can choose (optional)</span>
                    <input v-model.number="questionForm.settings.max_selections" type="number" min="1" max="50" class="mt-1 w-32 rounded border-gray-300" />
                </label>
                <div v-if="questionForm.type === 'number'" class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="font-medium text-gray-800">Lowest allowed (optional)</span>
                        <input v-model.number="questionForm.settings.min" type="number" class="mt-1 w-full rounded border-gray-300" />
                    </label>
                    <label class="block">
                        <span class="font-medium text-gray-800">Highest allowed (optional)</span>
                        <input v-model.number="questionForm.settings.max" type="number" class="mt-1 w-full rounded border-gray-300" />
                        <span v-if="questionForm.errors['settings.max']" class="text-red-700">{{ questionForm.errors['settings.max'] }}</span>
                    </label>
                </div>
                <label v-if="questionForm.type === 'file'" class="block">
                    <span class="font-medium text-gray-800">Largest file</span>
                    <select v-model.number="questionForm.settings.max_kb" class="mt-1 w-40 rounded border-gray-300">
                        <option :value="1024">1 MB</option>
                        <option :value="2048">2 MB</option>
                        <option :value="5120">5 MB</option>
                        <option :value="10240">10 MB</option>
                    </select>
                    <span class="mt-1 block text-xs text-gray-600">PDF, Word, Excel, PowerPoint, PNG or JPG. Files are private: only staff can download them.</span>
                </label>
            </form>
            <template #footer>
                <Button variant="secondary" @click="editing = null">Cancel</Button>
                <Button type="submit" form="question-form" :disabled="questionForm.processing">Save</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
