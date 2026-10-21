<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import LegalDocument from '@/Components/LegalDocument.vue';

const props = defineProps({ facts: Object });

const todo = (value) => value === props.facts.placeholder;

const sections = [
    { id: 'about', title: 'About these terms' },
    { id: 'account', title: 'Your account' },
    { id: 'registering', title: 'Registering for an event' },
    { id: 'in-person', title: 'In-person tickets and payment' },
    { id: 'refunds', title: 'Cancellations and refunds' },
    { id: 'programme', title: 'The programme and the livestream' },
    { id: 'recording', title: 'Recording and streaming' },
    { id: 'conduct', title: 'How to take part' },
    { id: 'speakers', title: 'Speakers' },
    { id: 'content', title: 'Event content' },
    { id: 'liability', title: 'Our responsibility' },
    { id: 'changes', title: 'Changes to these terms' },
    { id: 'law', title: 'Governing law' },
    { id: 'contact', title: 'Contact' },
];
</script>

<template>
    <PublicLayout>
        <Head title="Terms & Conditions" />

        <LegalDocument title="Terms & Conditions" :facts="facts" :version="facts.versions.terms" :sections="sections">
            <section id="about">
                <h2>1. About these terms</h2>
                <p>
                    These terms apply when you create an account, register for an event, or otherwise use this seminar platform, which
                    <strong>{{ facts.legal_name }}</strong> ("we", "us") runs. By ticking the box to accept them, you agree to them.
                    How we handle your personal information is covered by our <Link :href="route('legal.privacy')">Privacy Policy</Link>,
                    which forms part of these terms.
                </p>
            </section>

            <section id="account">
                <h2>2. Your account</h2>
                <ul>
                    <li>Give accurate details, and keep them up to date on your Account page.</li>
                    <li>Your account is for you alone. Keep your password private and tell us if you think someone else has used your account.</li>
                    <li>We email you a link to confirm your address and set a password. Until you've done that, some features stay locked.</li>
                    <li>You can delete your account at any time from your Account page.</li>
                </ul>
            </section>

            <section id="registering">
                <h2>3. Registering for an event</h2>
                <ul>
                    <li>You can attend online or, where offered, in person. The price, if any, is the one shown when you register.</li>
                    <li>Registration is confirmed by email. Places can be limited, and we may decline or cancel a registration that is fraudulent, duplicated or breaks these terms.</li>
                    <li>Online attendance gives you the livestream, live Q&amp;A, polls and the event resources, subject to section 6.</li>
                </ul>
            </section>

            <section id="in-person">
                <h2>4. In-person tickets and payment</h2>
                <ul>
                    <li>In-person tickets are paid by bank transfer to the account shown on your Payment page, using the reference given there.</li>
                    <li>Upload your proof of payment. We confirm your ticket once the payment reflects, and we may reject a proof that we can't match.</li>
                    <li>Your ticket (with its QR code) admits the registered attendee. Please bring it on your phone or printed.</li>
                    <li>While your payment is still being checked you can follow the event online.</li>
                </ul>
            </section>

            <section id="refunds">
                <h2>5. Cancellations and refunds</h2>
                <p><span :class="{ todo: todo(facts.refund_policy) }">{{ facts.refund_policy }}</span></p>
                <p>
                    If we cancel an event, we will tell you by email and refund in-person tickets in full. Nothing in these terms limits your
                    rights under the Consumer Protection Act, 2008, where it applies.
                </p>
            </section>

            <section id="programme">
                <h2>6. The programme and the livestream</h2>
                <ul>
                    <li>Speakers, times and topics can change. We'll post significant changes on the platform and by announcement.</li>
                    <li>The livestream depends on your connection and on third-party services. We run a backup stream and publish a recording where we can, but we can't promise an uninterrupted stream.</li>
                </ul>
            </section>

            <section id="recording">
                <h2>7. Recording and streaming</h2>
                <ul>
                    <li>Sessions are livestreamed and may be recorded. The recording is shared with registered attendees and may be published.</li>
                    <li>If you attend in person, you may appear in photographs, the stream or the recording of the venue.</li>
                    <li>Approved questions may be shown on screen or read aloud, without your name unless you give it.</li>
                    <li>Please don't record, re-stream or redistribute the livestream or recordings without our written permission.</li>
                </ul>
            </section>

            <section id="conduct">
                <h2>8. How to take part</h2>
                <p>Questions, polls, reactions and networking are there to make the event better. Please:</p>
                <ul>
                    <li>be respectful: no harassment, hate speech, threats or personal attacks;</li>
                    <li>keep it relevant: no spam, advertising or repeated posts;</li>
                    <li>post nothing unlawful, confidential or that infringes someone else's rights;</li>
                    <li>use networking contacts only to connect about the event's topics, not for bulk marketing.</li>
                </ul>
                <p>
                    Moderators approve questions before other attendees see them, and may hide any question. We may remove networking access
                    or suspend an account that breaks these rules.
                </p>
            </section>

            <section id="speakers">
                <h2>9. Speakers</h2>
                <ul>
                    <li>A speaker's stage link is personal: don't share it. We can withdraw it at any time.</li>
                    <li>By presenting, speakers allow us to stream and record their session and to share their slides as event resources, unless we agree otherwise in advance.</li>
                    <li>Speakers are responsible for having the rights to what they present, including anything shown on a shared screen.</li>
                </ul>
            </section>

            <section id="content">
                <h2>10. Event content</h2>
                <p>
                    Slides, recordings and other resources belong to us or to the speakers. You may use them for your own learning and within
                    your organisation. Don't sell or republish them. Our name and logo may not be used without permission.
                </p>
            </section>

            <section id="liability">
                <h2>11. Our responsibility</h2>
                <p>
                    We take care to run the platform and our events well, but the content is general information, not professional advice for
                    your situation. To the extent the law allows, we aren't liable for indirect or consequential loss, or for services run by others
                    (such as the livestream providers). Nothing here excludes liability that the law doesn't allow us to exclude, including under
                    the Consumer Protection Act.
                </p>
            </section>

            <section id="changes">
                <h2>12. Changes to these terms</h2>
                <p>
                    If we change these terms in a way that matters, we'll update the version and date above and ask you to accept the new version
                    the next time you sign in. If you don't accept, you can still download your information and delete your account.
                </p>
            </section>

            <section id="law">
                <h2>13. Governing law</h2>
                <p>These terms are governed by the law of the Republic of South Africa, and South African courts have jurisdiction.</p>
            </section>

            <section id="contact">
                <h2>14. Contact</h2>
                <p>
                    {{ facts.legal_name }},
                    <span :class="{ todo: todo(facts.physical_address) }">{{ facts.physical_address }}</span>.
                    Email: <span :class="{ todo: todo(facts.contact_email) }">{{ facts.contact_email }}</span>.
                </p>
            </section>
        </LegalDocument>
    </PublicLayout>
</template>
