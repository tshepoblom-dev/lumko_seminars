<script setup>
import CopyValue from '@/Components/CopyValue.vue';
import PortalPage from '@/Components/PortalPage.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    partner: Object,
    event: Object,
    tier: { type: String, default: null },
    features: { type: Array, default: () => [] },
    link: String,
    qrSvg: String,
    counts: Object, // { referred, verified, took_part, converted }
});
</script>

<template>
    <PartnerLayout :partner="partner" :event="event" :features="features">
        <Head :title="`Referrals · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Referrals" width="wide">
            <div class="grid gap-10 lg:grid-cols-12">
                <section class="lg:col-span-7" aria-labelledby="link-title">
                    <h2 id="link-title" class="text-xl text-ink-900">Your referral link</h2>
                    <p class="mt-2 max-w-prose text-[0.9375rem] text-ink-600">
                        Share this with your network. When someone registers through it, {{ partner.name }} is already selected under
                        “How did you hear about us?”. People can also choose you from that list themselves.
                    </p>
                    <dl class="mt-4 max-w-xl">
                        <CopyValue :value="link" label="Referral link" />
                    </dl>
                    <!-- eslint-disable-next-line vue/no-v-html -- server-generated SVG -->
                    <div class="mt-6 inline-block border border-rule bg-white p-3" role="img" :aria-label="`QR code for ${partner.name}'s referral link`" v-html="qrSvg"></div>
                </section>

                <section class="lg:col-span-5" aria-labelledby="counts-title">
                    <h2 id="counts-title" class="text-xl text-ink-900">What your referrals came to</h2>
                    <dl class="mt-4 divide-y divide-rule border-y border-ink-900">
                        <div class="flex items-baseline justify-between py-3">
                            <dt class="text-sm text-ink-600">Registered naming {{ partner.name }}</dt>
                            <dd class="tnum font-display text-2xl text-ink-900">{{ counts.referred }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between py-3">
                            <dt class="text-sm text-ink-600">Confirmed their email</dt>
                            <dd class="tnum text-xl text-ink-900">{{ counts.verified }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between py-3">
                            <dt class="text-sm text-ink-600">Took part</dt>
                            <dd class="tnum text-xl text-ink-900">{{ counts.took_part }}</dd>
                        </div>
                        <div class="flex items-baseline justify-between py-3">
                            <dt class="text-sm text-ink-600">Became Lumko clients</dt>
                            <dd class="tnum font-display text-2xl text-ink-900">{{ counts.converted }}</dd>
                        </div>
                    </dl>
                    <p class="mt-4 text-meta text-ink-500">
                        Counts only: naming you when registering doesn't share anyone's details with you. “Took part” means checked in at the door,
                        or asked a question, chatted or reacted online, so the real number may be higher. Our team records who became a client.
                    </p>
                </section>
            </div>
        </PortalPage>
    </PartnerLayout>
</template>
