<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/Components/Button.vue';
import StepIndicator from '@/Components/StepIndicator.vue';
import EventFacts from '@/Components/EventFacts.vue';
import { longDate, timeRange } from '@/lib/eventFormat';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import QuestionField from '@/Components/QuestionField.vue';
import ChoiceTiles from '@/Components/ChoiceTiles.vue';

const props = defineProps({
    event: Object,
    // Steps 4 and 5: the event's own questions (RegistrationForm::schema),
    // [{ page, title, questions: [...] }]. A page without questions is skipped.
    questionPages: { type: Array, default: () => [] },
    // Staff preview from Event → Registration form: every step can be
    // walked through, nothing is checked or submitted.
    preview: { type: Boolean, default: false },
    industryOptions: { type: Array, default: () => [] },
    organisationSizeOptions: { type: Array, default: () => [] },
    authUser: Object,
    // From an invitation link (/attend?code=…). Only pre-fills; the code is
    // checked on step 3 and used up when the registration is submitted.
    prefillVoucherCode: { type: String, default: null },
    // "How did you hear about us?": general sources, and this event's
    // partners ([{ id, name, can_share }]). A partner's referral link
    // (/attend?ref=…) pre-selects one.
    referralSources: { type: Array, default: () => [] },
    referralPartners: { type: Array, default: () => [] },
    prefillReferralPartnerId: { type: Number, default: null },
    partnerConsentTemplate: { type: String, default: '' },
});

const page = usePage();

const allQuestions = computed(() => props.questionPages.flatMap((p) => p.questions));

// An empty answer of the right shape for each question type.
function blankAnswer(question) {
    if (question.type === 'multi_choice') return [];
    if (question.type === 'yes_no' || question.type === 'file') return null;
    return '';
}

const form = useForm({
    event_id: props.event.id,
    first_name: props.authUser?.first_name ?? '',
    last_name: props.authUser?.last_name ?? '',
    email: props.authUser?.email ?? '',
    mobile: props.authUser?.mobile ?? '',
    organisation: props.authUser?.organisation ?? '',
    job_title: props.authUser?.job_title ?? '',
    industry: '',
    organisation_size: '',
    referral_source: '',
    referral_partner_id: null,
    share_with_partner: false,
    // An in-person-only event has one choice (event.offers_online).
    attendance_mode: props.event.offers_online === false ? 'in_person' : 'online',
    voucher_code: '',
    // question id → answer
    answers: Object.fromEntries(props.questionPages.flatMap((p) => p.questions).map((q) => [q.id, blankAnswer(q)])),
    networking_consent: false,
    lumko_marketing_consent: false,
    accept_legal: false,
});

// D-Registration — the draft never carries free text, either consent
// checkbox or the terms acceptance; those are re-decided fresh every time,
// never quietly restored from an earlier visit. Nor an invitation code:
// it's single-use, and the invitation link re-fills it. Of the event's
// own questions only choice and yes/no answers are kept (no free text,
// and a chosen file can't be stored anyway).
const DRAFT_EXCLUDED_FIELDS = ['networking_consent', 'lumko_marketing_consent', 'share_with_partner', 'accept_legal', 'event_id', 'voucher_code'];
const DRAFT_ANSWER_TYPES = ['single_choice', 'multi_choice', 'yes_no'];
const draftKey = `attend-draft:${props.event.id}`;

function saveDraft() {
    if (props.authUser || props.preview) return;
    try {
        const data = form.data();
        const draft = {};
        for (const key in data) {
            if (!DRAFT_EXCLUDED_FIELDS.includes(key) && key !== 'answers') draft[key] = data[key];
        }
        draft.answers = Object.fromEntries(allQuestions.value
            .filter((q) => DRAFT_ANSWER_TYPES.includes(q.type))
            .map((q) => [q.id, data.answers[q.id]]));
        sessionStorage.setItem(draftKey, JSON.stringify(draft));
    } catch {
        // Private browsing / blocked storage — the stepper still works, it just won't survive a reload.
    }
}

function loadDraft() {
    if (props.authUser || props.preview) return;
    try {
        const raw = sessionStorage.getItem(draftKey);
        if (!raw) return;
        const draft = JSON.parse(raw);
        for (const key in draft) {
            if (!DRAFT_EXCLUDED_FIELDS.includes(key) && key !== 'answers' && key in form) form[key] = draft[key];
        }
        // Only answers to questions still on the form, of a draftable type.
        for (const q of allQuestions.value) {
            if (DRAFT_ANSWER_TYPES.includes(q.type) && draft.answers && q.id in draft.answers) {
                form.answers[q.id] = draft.answers[q.id];
            }
        }
    } catch {
        // Corrupt or inaccessible draft — start fresh rather than fail.
    }
}

onMounted(() => {
    loadDraft();
    // A draft saved while online was still offered can't bring it back.
    if (props.event.offers_online === false) form.attendance_mode = 'in_person';
    // After the draft, so an earlier "Online" draft can't undo an invitation link.
    if (props.prefillVoucherCode) {
        form.voucher_code = props.prefillVoucherCode;
        form.attendance_mode = 'in_person';
    }
    // Likewise a partner's referral link.
    if (props.prefillReferralPartnerId && props.referralPartners.some((p) => p.id === props.prefillReferralPartnerId)) {
        form.referral_source = 'partner';
        form.referral_partner_id = props.prefillReferralPartnerId;
    }
});

// "How did you hear about us?" is one select over both kinds of answer:
// "source:linkedin" or "partner:12".
const referralChoice = computed({
    get: () => (form.referral_source === 'partner'
        ? (form.referral_partner_id ? `partner:${form.referral_partner_id}` : '')
        : (form.referral_source ? `source:${form.referral_source}` : '')),
    set: (value) => {
        const [kind, id] = String(value || '').split(':');
        form.referral_source = kind === 'partner' ? 'partner' : (kind === 'source' ? id : '');
        form.referral_partner_id = kind === 'partner' ? Number(id) : null;
        form.share_with_partner = false; // a new answer is never a carried-over consent
    },
});

// The tiles: "Prefer not to say", the general sources (with icons), then
// the event's partners (with their logos).
const referralTileGroups = computed(() => [
    {
        options: [
            { value: '', label: 'Prefer not to say', icon: 'none' },
            ...props.referralSources.map((s) => ({ value: `source:${s.value}`, label: s.label, icon: s.value })),
        ],
    },
    ...(props.referralPartners.length
        ? [{
            heading: 'One of our partners',
            options: props.referralPartners.map((p) => ({ value: `partner:${p.id}`, label: p.name, logoUrl: p.logo_url, logoAlt: p.logo_alt })),
        }]
        : []),
]);

const referralPartner = computed(() => (form.referral_source === 'partner'
    ? props.referralPartners.find((p) => p.id === form.referral_partner_id) ?? null
    : null));

// The exact sentence ConsentService records (it fills :partner the same way).
const partnerConsentText = computed(() => (referralPartner.value
    ? props.partnerConsentTemplate.replaceAll(':partner', referralPartner.value.name)
    : ''));

const referralLabel = computed(() => {
    if (referralPartner.value) return referralPartner.value.name;
    return props.referralSources.find((s) => s.value === form.referral_source)?.label ?? 'Not given';
});

// Invitation code (step 3, In person only). "Accepted" once step 3 has
// passed with it — the server checked the code — and reset by any edit.
const codeAccepted = ref(false);
const showCodeField = ref(false);
const codeInput = ref(null);
const codeFieldOpen = computed(() => showCodeField.value || form.voucher_code !== '');

watch(() => form.voucher_code, () => { codeAccepted.value = false; });
watch(() => form.attendance_mode, (mode) => {
    if (mode !== 'in_person') {
        form.voucher_code = '';
        showCodeField.value = false;
    }
});

async function openCodeField() {
    showCodeField.value = true;
    await nextTick();
    codeInput.value?.focus();
}

// Steps keep the server's numbering (1–7, what validate-step checks);
// steps 4 and 5 are the event's question pages and drop out when empty
// (step 5 is empty unless staff add questions to it). What the visitor
// sees is numbered by position among the steps shown.
const questionPage = (step) => props.questionPages.find((p) => p.page === step - 3) ?? null;

const stepDefs = computed(() => [
    { step: 1, label: 'Your details' },
    { step: 2, label: 'Organisation & role' },
    { step: 3, label: 'Attendance' },
    { step: 4, label: questionPage(4)?.title ?? 'Interests', skip: !questionPage(4)?.questions.length },
    { step: 5, label: questionPage(5)?.title ?? 'A few more questions', skip: !questionPage(5)?.questions.length },
    { step: 6, label: 'Networking' },
    { step: 7, label: 'Staying in touch' },
].filter((s) => !s.skip));

const steps = computed(() => stepDefs.value.map((s) => ({ label: s.label })));
const position = (step) => stepDefs.value.findIndex((s) => s.step === step) + 1;
const nextStepAfter = (step) => stepDefs.value.find((s) => s.step > step)?.step ?? null;
const previousStepBefore = (step) => [...stepDefs.value].reverse().find((s) => s.step < step)?.step ?? null;

const currentStep = ref(1);
const indicatorCurrent = computed(() => (showReview.value ? steps.value.length + 1 : position(currentStep.value)));
const showReview = ref(false);
const stepErrors = ref({});
// A failed check that isn't a field error (rate limited, server error,
// connection dropped) — shown in the same focused alert so it's never silent.
const requestError = ref(null);
const validating = ref(false);
const headingRef = ref(null);
const errorSummaryRef = ref(null);

const hasErrors = computed(() => requestError.value !== null || Object.keys(stepErrors.value).length > 0);

async function focusTop() {
    await nextTick();
    (hasErrors.value ? errorSummaryRef.value : headingRef.value)?.focus();
}

async function next() {
    stepErrors.value = {};
    requestError.value = null;
    validating.value = true;

    try {
        if (!props.preview) {
            // Files can't travel in the JSON step check; they're checked on submit.
            const data = form.data();
            const answers = Object.fromEntries(Object.entries(data.answers).map(([id, v]) => [id, v instanceof File ? null : v]));
            await axios.post(route('event.register.validate-step'), { step: currentStep.value, ...data, answers });
        }

        if (currentStep.value === 3) {
            codeAccepted.value = form.attendance_mode === 'in_person' && form.voucher_code.trim() !== '';
        }

        saveDraft();

        const following = nextStepAfter(currentStep.value);
        if (following) {
            currentStep.value = following;
        } else {
            showReview.value = true;
        }
        await focusTop();
    } catch (error) {
        if (error.response?.status === 422) {
            stepErrors.value = error.response.data.errors ?? {};
        } else if (error.response?.status === 429) {
            requestError.value = 'Too many requests — please wait a minute and try again.';
        } else {
            requestError.value = "We couldn't reach the server. Check your connection and try again.";
        }
        await focusTop();
    } finally {
        validating.value = false;
    }
}

function back() {
    stepErrors.value = {};
    requestError.value = null;
    if (showReview.value) {
        showReview.value = false;
        currentStep.value = stepDefs.value[stepDefs.value.length - 1].step;
    } else if (previousStepBefore(currentStep.value)) {
        currentStep.value = previousStepBefore(currentStep.value);
    }
    focusTop();
}

function editStep(step) {
    showReview.value = false;
    currentStep.value = step;
    stepErrors.value = {};
    requestError.value = null;
    focusTop();
}

const legalError = ref(null);
const legalCheckbox = ref(null);

function submit() {
    if (props.preview) return;
    legalError.value = null;
    if (!form.accept_legal) {
        legalError.value = 'Please accept the Terms & Conditions and the Privacy Policy to register.';
        legalCheckbox.value?.$el?.focus?.();
        return;
    }

    form.post(route('event.register.store'), {
        onSuccess: () => {
            try { sessionStorage.removeItem(draftKey); } catch { /* nothing to clean up */ }
        },
    });
}

// Keep the draft warm as the visitor types, not only on step transitions.
watch(() => form.data(), () => saveDraft(), { deep: true });

const attendanceLabel = computed(() => {
    if (form.attendance_mode !== 'in_person') return 'Online — Free';
    return codeAccepted.value
        ? 'In person — Complimentary (invitation code)'
        : `In person — R${props.event.in_person_price.toFixed(0)}`;
});

// An answer as the review panel shows it.
function answerText(question) {
    const v = form.answers[question.id];
    const label = (id) => question.options.find((o) => o.id === id)?.label ?? id;
    if (v === null || v === '' || (Array.isArray(v) && !v.length)) return '—';
    if (question.type === 'multi_choice') return v.map(label).join(', ');
    if (question.type === 'single_choice') return label(v);
    if (question.type === 'yes_no') return v ? 'Yes' : 'No';
    if (question.type === 'file') return v?.name ?? '—';
    return String(v);
}

const answerSubmitErrors = computed(() => Object.entries(form.errors)
    .filter(([key]) => key.startsWith('answers.'))
    .map(([key, message]) => {
        const id = Number(key.split('.')[1]);
        const qp = props.questionPages.find((p) => p.questions.some((q) => q.id === id));
        return { key, message, step: (qp?.page ?? 1) + 3 };
    }));

function answerError(question) {
    const key = `answers.${question.id}`;
    return stepErrors.value[key]?.[0] ?? stepErrors.value[`${key}.0`]?.[0] ?? form.errors[key] ?? form.errors[`${key}.0`] ?? null;
}

const reviewRows = computed(() => [
    { label: 'Name', value: `${form.first_name} ${form.last_name}`, step: 1 },
    { label: 'Email', value: form.email, step: 1 },
    { label: 'Mobile', value: form.mobile || 'Not given', step: 1 },
    { label: 'Organisation', value: `${form.organisation} · ${form.job_title}`, step: 2 },
    { label: 'Industry', value: form.industry || 'Not given', step: 2 },
    { label: 'Organisation size', value: form.organisation_size ? `${form.organisation_size} people` : 'Not given', step: 2 },
    { label: 'How you heard about us', value: referralLabel.value, step: 2 },
    ...(referralPartner.value?.can_share
        ? [{ label: `Details shared with ${referralPartner.value.name}`, value: form.share_with_partner ? 'Yes' : 'No', step: 2 }]
        : []),
    { label: 'Attending', value: attendanceLabel.value, step: 3 },
    ...props.questionPages.flatMap((p) => p.questions.map((q) => ({ label: q.label, value: answerText(q), step: p.page + 3 }))),
    { label: 'Networking', value: form.networking_consent ? 'Visible to other attendees' : 'Not visible', step: 6 },
    { label: 'Lumko communication', value: form.lumko_marketing_consent ? 'Opted in' : 'Not opted in', step: 7 },
]);
</script>
<template>
    <PublicLayout>
    <Head title="Register to attend" />

    <div class="shell grid gap-10 pb-16 pt-8 sm:pt-12 lg:grid-cols-12 lg:gap-6 lg:pb-24 lg:pt-16">
        <!-- Side column: what you are registering for, and the running order of the form. -->
        <aside class="hidden lg:col-span-4 lg:block">
            <div class="sticky top-8 pt-3">
                <p class="eyebrow text-ink-400">When and where</p>
                <EventFacts v-if="event.starts_at" :event="event" layout="stack" class="mt-4" />
                <p class="eyebrow mt-10 text-ink-400">Your registration</p>
                <div class="mt-4">
                    <StepIndicator :steps="steps" :current="indicatorCurrent" layout="list" />
                </div>
            </div>
        </aside>

        <div class="lg:col-span-7 lg:col-start-6">
            <p v-if="preview" class="mb-6 border-t-2 border-signal-500 bg-paper p-3 text-sm text-ink-800" role="note">
                Staff preview of this event's registration form. Nothing is checked or saved.
                <a :href="route('admin.events.registration-form.index', event.id)" class="link">Back to the form editor</a>
            </p>
            <h1 ref="headingRef" tabindex="-1" class="display text-display-md text-ink-900 outline-none">
                Register for {{ event.name }}
            </h1>
            <p v-if="event.starts_at" class="tnum mt-3 text-sm text-ink-500 lg:hidden">
                {{ longDate(event.starts_at) }} &middot; {{ timeRange(event.starts_at, event.ends_at) }}
            </p>

            <p v-if="page.props.errors.email" class="mt-6 border-t-2 border-red-700 pt-3 text-sm text-ink-900">
                {{ page.props.errors.email }}
                <a href="/login" class="link">Log in</a>
            </p>

            <p v-if="prefillVoucherCode" class="mt-6 border-t-2 border-signal-500 pt-3 text-sm text-ink-700">
                Your invitation code is filled in for you on step 3: it makes your in-person ticket complimentary.
                <template v-if="!authUser">
                    Already have an account?
                    <a :href="route('voucher.redeem', { code: prefillVoucherCode })" class="link">Sign in to claim your ticket</a>.
                </template>
            </p>

            <div class="mt-8 lg:hidden">
                <StepIndicator :steps="steps" :current="indicatorCurrent" />
            </div>

            <div
                v-if="hasErrors"
                ref="errorSummaryRef"
                tabindex="-1"
                role="alert"
                class="mt-8 border border-rule border-t-2 border-t-red-700 bg-white p-4 text-sm text-ink-900 outline-none"
            >
                <p v-if="requestError" class="font-semibold">{{ requestError }}</p>
                <template v-else>
                    <p class="font-semibold">Please fix the following before continuing:</p>
                    <ul class="mt-2 list-inside list-disc text-red-800">
                        <li v-for="(messages, field) in stepErrors" :key="field">{{ messages[0] }}</li>
                    </ul>
                </template>
            </div>

            <form class="mt-10 border-t border-ink-900 pt-8" @submit.prevent>
                <fieldset v-if="!showReview && currentStep === 1" class="space-y-6">
                    <legend class="step-legend">1. Your details</legend>
                    <p v-if="!authUser" class="text-sm text-ink-600">
                        Before you finish, we'll ask you to accept our
                        <a :href="route('legal.terms')" target="_blank" rel="noopener" class="link">Terms &amp; Conditions<span class="sr-only"> (opens in a new tab)</span></a>
                        and
                        <a :href="route('legal.privacy')" target="_blank" rel="noopener" class="link">Privacy Policy<span class="sr-only"> (opens in a new tab)</span></a>,
                        which explain how we use your details.
                    </p>
                    <p v-if="authUser" class="text-sm text-ink-500">Your name and email come from your account.</p>
                    <!-- Name and email are locked when signed in; mobile never is — many accounts have none on file. -->
                    <div class="grid gap-6 sm:grid-cols-2">
                        <FormField id="public-register-1" label="First name" :error="stepErrors.first_name?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                            <TextInput :id="id" v-model="form.first_name" type="text" autocomplete="given-name" :disabled="!!authUser" :aria-describedby="describedBy" :aria-invalid="invalid" />
                        </FormField>
                        <FormField id="public-register-2" label="Last name" :error="stepErrors.last_name?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                            <TextInput :id="id" v-model="form.last_name" type="text" autocomplete="family-name" :disabled="!!authUser" :aria-describedby="describedBy" :aria-invalid="invalid" />
                        </FormField>
                    </div>
                    <FormField id="public-register-3" label="Email" :error="stepErrors.email?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" v-model="form.email" type="email" autocomplete="email" :disabled="!!authUser" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>
                    <FormField id="public-register-4" label="Mobile" hint="Optional" :error="stepErrors.mobile?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" v-model="form.mobile" type="text" inputmode="tel" autocomplete="tel" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>
                </fieldset>

                <fieldset v-if="!showReview && currentStep === 2" class="space-y-6">
                    <legend class="step-legend">2. Organisation & role</legend>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <FormField id="public-register-5" label="Organisation" :error="stepErrors.organisation?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                            <TextInput :id="id" v-model="form.organisation" type="text" autocomplete="organization" :aria-describedby="describedBy" :aria-invalid="invalid" />
                        </FormField>
                        <FormField id="public-register-6" label="Job title" :error="stepErrors.job_title?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                            <TextInput :id="id" v-model="form.job_title" type="text" autocomplete="organization-title" :aria-describedby="describedBy" :aria-invalid="invalid" />
                        </FormField>
                    </div>
                    <div class="grid gap-6 sm:grid-cols-2">
                        <FormField id="public-register-industry" label="Industry" hint="Optional" :error="stepErrors.industry?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                            <select :id="id" v-model="form.industry" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid">
                                <option value="">Prefer not to say</option>
                                <option v-for="option in industryOptions" :key="option" :value="option">{{ option }}</option>
                            </select>
                        </FormField>
                        <FormField id="public-register-size" label="Organisation size" hint="Optional" :error="stepErrors.organisation_size?.[0]" :announce="false" v-slot="{ id, describedBy, invalid }">
                            <select :id="id" v-model="form.organisation_size" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid">
                                <option value="">Prefer not to say</option>
                                <option v-for="option in organisationSizeOptions" :key="option" :value="option">{{ option }} people</option>
                            </select>
                        </FormField>
                    </div>
                    <ChoiceTiles
                        id="public-register-referral"
                        v-model="referralChoice"
                        legend="How did you hear about us?"
                        hint="Optional"
                        :error="stepErrors.referral_source?.[0] || stepErrors.referral_partner_id?.[0]"
                        :groups="referralTileGroups"
                    />
                    <label v-if="referralPartner?.can_share" class="flex items-start gap-3 text-sm text-ink-800">
                        <Checkbox v-model:checked="form.share_with_partner" class="mt-0.5" />
                        <span>
                            {{ partnerConsentText }}
                            <span class="mt-1 block text-meta text-ink-500">Optional. Telling us who sent you doesn't share anything with them; only this box does.</span>
                        </span>
                    </label>
                </fieldset>

                <fieldset v-if="!showReview && currentStep === 3" class="space-y-3">
                    <legend class="step-legend">3. How will you attend?</legend>
                    <label v-if="event.offers_online !== false" class="choice">
                        <input v-model="form.attendance_mode" type="radio" value="online" />
                        <span class="flex-1">
                            <span class="flex items-baseline justify-between gap-4">
                                <span class="font-medium">Online</span>
                                <span class="tnum font-display text-xl" style="font-stretch: 86%; font-weight: 700">Free</span>
                            </span>
                            <span class="mt-0.5 block text-sm text-ink-500">Join the live session from your dashboard.</span>
                        </span>
                    </label>
                    <label class="choice">
                        <input v-model="form.attendance_mode" type="radio" value="in_person" />
                        <span class="flex-1">
                            <span class="flex items-baseline justify-between gap-4">
                                <span class="font-medium">In person</span>
                                <span class="tnum font-display text-xl" style="font-stretch: 86%; font-weight: 700">{{ codeAccepted ? 'Complimentary' : `R${event.in_person_price.toFixed(0)}` }}</span>
                            </span>
                            <span class="mt-0.5 block text-sm text-ink-500">
                                <template v-if="event.venue_name">At {{ event.venue_name }}. </template>
                                <template v-if="codeAccepted">Your invitation code covers your seat: your ticket is confirmed as soon as you register.</template>
                                <template v-else>Pay by EFT and upload your proof of payment; your ticket is issued once payment is approved.</template>
                            </span>
                        </span>
                    </label>
                    <p v-if="event.offers_online === false" class="text-sm text-ink-600">
                        This event is in person only. The session is recorded, and the recording will be shared after the event.
                    </p>
                    <p v-if="stepErrors.attendance_mode" class="field-error">{{ stepErrors.attendance_mode[0] }}</p>

                    <!-- A sibling of the radios, not inside the In person label: no input nested in another control's label. -->
                    <div v-if="form.attendance_mode === 'in_person'" class="pt-3">
                        <!-- Replaced by the field (which then takes focus) once pressed. -->
                        <button v-if="!codeFieldOpen" type="button" class="link text-sm" @click="openCodeField">
                            Have an invitation code?
                        </button>
                        <FormField
                            v-else
                            id="public-register-voucher"
                            label="Invitation code"
                            hint="Only if you've been invited as our guest. We check it when you continue."
                            :error="stepErrors.voucher_code?.[0] ?? form.errors.voucher_code"
                            :announce="false"
                            v-slot="{ id, describedBy, invalid }"
                        >
                            <TextInput
                                :id="id"
                                ref="codeInput"
                                v-model="form.voucher_code"
                                type="text"
                                autocomplete="off"
                                autocapitalize="characters"
                                spellcheck="false"
                                class="tnum uppercase tracking-wide sm:max-w-xs"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                            />
                        </FormField>
                    </div>
                </fieldset>

                <!-- Steps 4 and 5: this event's own questions. -->
                <template v-for="qp in questionPages" :key="qp.page">
                    <fieldset v-if="!showReview && currentStep === qp.page + 3 && qp.questions.length" class="space-y-8">
                        <legend class="step-legend">{{ position(qp.page + 3) }}. {{ qp.title }}</legend>
                        <QuestionField
                            v-for="q in qp.questions"
                            :key="q.id"
                            v-model="form.answers[q.id]"
                            :question="q"
                            :error="answerError(q)"
                        />
                    </fieldset>
                </template>

                <fieldset v-if="!showReview && currentStep === 6" class="space-y-3">
                    <legend class="step-legend">{{ position(6) }}. Networking</legend>
                    <label class="choice text-sm">
                        <Checkbox v-model:checked="form.networking_consent" />
                        <span>
                            Make my profile (name, organisation, job title, interests) visible to other attendees.
                            Your email and mobile number are never shared.
                        </span>
                    </label>
                </fieldset>

                <fieldset v-if="!showReview && currentStep === 7" class="space-y-3">
                    <legend class="step-legend">{{ position(7) }}. Staying in touch</legend>
                    <label class="choice text-sm">
                        <Checkbox v-model:checked="form.lumko_marketing_consent" />
                        <span>
                            I'd like Lumko to share relevant technology insights, future events, resources or
                            information about services that may be useful to my organisation. You can withdraw
                            this at any time.
                        </span>
                    </label>
                </fieldset>

                <div v-if="showReview">
                    <h2 class="step-legend">Review your registration</h2>
                    <!-- The code is checked again on submit (used meanwhile, revoked…). -->
                    <p v-if="form.errors.voucher_code" role="alert" class="mb-6 border-t-2 border-red-700 pt-3 text-sm text-ink-900">
                        {{ form.errors.voucher_code }}
                        <button type="button" class="link" @click="editStep(3)">Change the code</button>
                    </p>
                    <!-- Answers checked only on submit (e.g. an uploaded file). -->
                    <div v-if="answerSubmitErrors.length" role="alert" class="mb-6 border-t-2 border-red-700 pt-3 text-sm text-ink-900">
                        <p v-for="e in answerSubmitErrors" :key="e.key">
                            {{ e.message }}
                            <button type="button" class="link" @click="editStep(e.step)">Fix this</button>
                        </p>
                    </div>
                    <dl class="mt-6 border-y border-ink-900">
                        <div
                            v-for="row in reviewRows"
                            :key="row.label"
                            class="grid grid-cols-[1fr_auto] items-start gap-x-4 gap-y-0.5 border-t border-rule py-3.5 first:border-t-0 sm:grid-cols-[10rem_1fr_auto]"
                        >
                            <dt class="eyebrow pt-0.5 text-ink-400">{{ row.label }}</dt>
                            <dd class="col-start-1 row-start-2 text-[0.9375rem] text-ink-900 sm:col-start-2 sm:row-start-1">{{ row.value }}</dd>
                            <dd class="col-start-2 row-span-2 row-start-1 sm:col-start-3">
                                <button type="button" class="link text-sm" :aria-label="`Edit ${row.label.toLowerCase()}`" @click="editStep(row.step)">Edit</button>
                            </dd>
                        </div>
                    </dl>

                    <label class="choice mt-6 text-sm">
                        <Checkbox ref="legalCheckbox" v-model:checked="form.accept_legal" name="accept_legal" :aria-invalid="!!(legalError || form.errors.accept_legal)" aria-describedby="register-legal-error" />
                        <span>
                            I have read and accept the
                            <a :href="route('legal.terms')" target="_blank" rel="noopener" class="link">Terms &amp; Conditions<span class="sr-only"> (opens in a new tab)</span></a>
                            and the
                            <a :href="route('legal.privacy')" target="_blank" rel="noopener" class="link">Privacy Policy<span class="sr-only"> (opens in a new tab)</span></a>.
                        </span>
                    </label>
                    <p id="register-legal-error" class="field-error" role="alert">{{ legalError || form.errors.accept_legal }}</p>
                </div>

                <!-- Actions: pinned to the bottom of the screen on phones so Next is always in reach. -->
                <div class="sticky bottom-0 z-10 -mx-4 mt-10 flex items-center justify-between gap-4 border-t border-rule bg-white/95 px-4 py-3 backdrop-blur-sm sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:px-0 sm:py-0 sm:backdrop-blur-none">
                    <Button v-if="currentStep > 1 || showReview" variant="secondary" @click="back">Back</Button>
                    <span v-else></span>

                    <Button v-if="!showReview" :disabled="validating" arrow @click="next">
                        {{ validating ? 'Checking…' : (currentStep === 7 ? 'Review' : 'Next') }}
                    </Button>
                    <p v-else-if="preview" class="text-sm font-medium text-ink-700" role="status">This is a preview: nothing is submitted.</p>
                    <Button v-else size="lg" :disabled="form.processing" @click="submit">
                        Complete registration
                    </Button>
                </div>
            </form>
        </div>
    </div>
    </PublicLayout>
</template>

<style scoped>
.step-legend {
    @apply mb-6 font-display text-2xl text-ink-900 sm:text-[1.75rem];
    font-stretch: 86%;
    font-weight: 720;
    line-height: 1.1;
}

.step-optional {
    @apply font-sans text-sm font-normal text-ink-400;
}
</style>
