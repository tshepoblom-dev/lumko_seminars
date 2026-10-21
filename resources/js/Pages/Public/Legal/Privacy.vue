<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import LegalDocument from '@/Components/LegalDocument.vue';

// Written from what the platform actually does (see docs/security.md and
// the models). Organiser-only facts come from config/legal.php.
const props = defineProps({ facts: Object });

const todo = (value) => value === props.facts.placeholder;

const sections = [
    { id: 'who', title: 'Who we are' },
    { id: 'collect', title: 'What we collect' },
    { id: 'why', title: 'Why we use it' },
    { id: 'visible', title: 'Who can see it' },
    { id: 'providers', title: 'Service providers' },
    { id: 'abroad', title: 'Information sent outside South Africa' },
    { id: 'keep', title: 'How long we keep it' },
    { id: 'security', title: 'How we protect it' },
    { id: 'rights', title: 'Your rights' },
    { id: 'cookies', title: 'Cookies and browser storage' },
    { id: 'marketing', title: 'Direct marketing' },
    { id: 'children', title: 'Children' },
    { id: 'changes', title: 'Changes to this policy' },
    { id: 'contact', title: 'Contact and complaints' },
];
</script>

<template>
    <PublicLayout>
        <Head title="Privacy Policy" />

        <LegalDocument title="Privacy Policy" :facts="facts" :version="facts.versions.privacy" :sections="sections">
            <section id="who">
                <h2>1. Who we are</h2>
                <p>
                    This policy explains how <strong>{{ facts.legal_name }}</strong>
                    (registration number <span :class="{ todo: todo(facts.registration_number) }">{{ facts.registration_number }}</span>,
                    <span :class="{ todo: todo(facts.physical_address) }">{{ facts.physical_address }}</span>)
                    handles personal information on this seminar platform: registration, the attendee portal,
                    the livestream and everything around our events. We are the <strong>responsible party</strong> under the
                    Protection of Personal Information Act, 2013 (POPIA).
                </p>
                <p>
                    Our Information Officer is <span :class="{ todo: todo(facts.information_officer_name) }">{{ facts.information_officer_name }}</span>,
                    reachable at <span :class="{ todo: todo(facts.information_officer_email) }">{{ facts.information_officer_email }}</span>.
                </p>
            </section>

            <section id="collect">
                <h2>2. What we collect</h2>
                <h3>When you register</h3>
                <ul>
                    <li>Your first and last name and email address, and your mobile number if you give one.</li>
                    <li>Your organisation and job title, and optionally your industry and organisation size.</li>
                    <li>Whether you attend online or in person, the topics you're interested in, and optionally a technology challenge your organisation faces.</li>
                    <li>Your answers to any further questions an event asks when you register, including any file you choose to upload. Only our event staff can see files.</li>
                    <li>Your choices about networking and about hearing from us (see sections 4 and 11).</li>
                    <li>Optionally, how you heard about the event, which can be one of the event's partners (see section 4).</li>
                </ul>
                <h3>If you attend in person</h3>
                <ul>
                    <li>The proof of payment you upload (a bank transfer slip), the payment's status and our notes on it.</li>
                    <li>Your ticket and the time you checked in at the venue.</li>
                </ul>
                <h3>While you use the platform</h3>
                <ul>
                    <li>Questions you submit, questions you upvote, poll answers, reactions during the livestream, and feedback you give after the event.</li>
                    <li>Which event resources (slides, recordings) you open, and networking requests you send or accept.</li>
                    <li>Records of what you agreed to and when (these terms, this policy, your consents), with the IP address and browser used.</li>
                    <li>Security and audit records of important actions, which include IP address and browser details.</li>
                </ul>
                <h3>Speakers and partners</h3>
                <p>
                    For speakers we publish the name, title, organisation, biography and (once approved) photo they give us. A speaker who presents
                    remotely sends their camera, microphone and, if they choose, their screen through the livestream, including in the private
                    green room where they test their set-up.
                </p>
                <p>
                    For an event's partners we publish the organisation's name, logo, description and website. People from a speaker or partner who
                    use their portal have an account like any attendee, and we record what they upload and share.
                </p>
                <p>We don't collect special personal information (such as health or religious beliefs), and we don't ask for ID numbers.</p>
            </section>

            <section id="why">
                <h2>3. Why we use it</h2>
                <ul>
                    <li><strong>To run the event you signed up for</strong>: your account, registration, ticket, payment check, check-in, the livestream, Q&amp;A, polls, resources and event emails (confirmation, reminders, changes, the post-event thank-you). This is necessary to provide what you registered for.</li>
                    <li><strong>Networking</strong>: only if you switch it on; you can switch it off at any time.</li>
                    <li><strong>Connecting you with a speaker or partner</strong>: only if you choose to (section 4); you can withdraw at any time.</li>
                    <li>
                        <strong>Understanding where our attendees and clients come from</strong>: if you tell us how you heard about the event, and if you
                        later become a Lumko client, we record that, so we can recognise the partners who refer people to us. Partners only ever see how
                        many of the people who named them registered, took part or became clients, never who.
                    </li>
                    <li><strong>News about future events and services</strong>: only if you opt in (section 11).</li>
                    <li><strong>Improving our events</strong>: your feedback, and totals of attendance and engagement that don't identify you.</li>
                    <li><strong>Security and records we must keep</strong>: preventing abuse, investigating problems, and keeping evidence of payments and consents.</li>
                </ul>
            </section>

            <section id="visible">
                <h2>4. Who can see it</h2>
                <ul>
                    <li><strong>Our event staff</strong> see registrations, payments and questions for the events they manage.</li>
                    <li><strong>Other attendees</strong> see your name, organisation, job title and interests only if you turn networking on. Your email and mobile number are never shown to them.</li>
                    <li><strong>Questions</strong> appear to other attendees only after a moderator approves them, and without your name.</li>
                    <li>
                        <strong>A speaker or partner</strong> receives your name, email, organisation and job title only if you choose to share them with
                        that speaker or partner: by connecting with them, sending a partner an enquiry (with your message), agreeing on a partner's screen
                        after they scan your ticket at their stand, or ticking the box when you register. You see the exact wording each time. We never
                        give speakers or partners the attendee list. You can withdraw from your account page at any time; they then stop seeing your
                        details, but a partner is responsible for any copy it has already made, and you can ask it directly to delete that.
                    </li>
                    <li>We don't sell your personal information.</li>
                </ul>
            </section>

            <section id="providers">
                <h2>5. Service providers</h2>
                <p>These operators process information for us, under agreements that require them to protect it:</p>
                <ul>
                    <li><strong>Hosting</strong>: <span :class="{ todo: todo(facts.hosting_provider) }">{{ facts.hosting_provider }}</span> stores the platform and its database.</li>
                    <li><strong>Email</strong>: our own mail server sends account and event emails.</li>
                    <li><strong>Pusher</strong> (real-time updates for Q&amp;A, polls and reactions) receives the updates shown on the live page, and the fact that your browser is connected.</li>
                    <li><strong>Agora</strong> (the interactive livestream) carries the video and audio. As a viewer your browser receives the stream under a random identifier, not your name. Presenters' camera, microphone and screen share go through Agora.</li>
                    <li><strong>YouTube or Facebook</strong>, only if you choose the backup stream or a recording that we host there; their own privacy policies then apply.</li>
                    <li><strong>Tawk.to</strong> (the chat window on some public pages) receives what you type into the chat and basic browser information.</li>
                    <li><strong>Bunny Fonts</strong> serves the typeface, which means your browser requests it from their servers.</li>
                </ul>
            </section>

            <section id="abroad">
                <h2>6. Information sent outside South Africa</h2>
                <p>
                    Pusher, Agora, Tawk.to and Bunny Fonts operate servers outside South Africa. We use them because they are needed to run a live,
                    interactive event. We only send information to them under data-protection terms that give it protection comparable to POPIA,
                    as section 72 of POPIA requires, and only what the service needs.
                </p>
            </section>

            <section id="keep">
                <h2>7. How long we keep it</h2>
                <ul>
                    <li>Proof-of-payment files are deleted automatically <strong>{{ facts.pop_retention_days ?? 'a set period' }} days</strong> after upload.</li>
                    <li>An event stays in your portal (recording, resources, feedback) for <strong>{{ facts.post_event_days }} days</strong> after it ends.</li>
                    <li>Your account stays until you delete it. Deleting it (on your Account page) removes your name, email, mobile, organisation, job title, password and anything you wrote in free text.</li>
                    <li>We keep payment records and the records of what you agreed to after deletion, with your personal details removed, because we may need them as financial or legal evidence.</li>
                </ul>
            </section>

            <section id="security">
                <h2>8. How we protect it</h2>
                <p>
                    Everything is sent over HTTPS. Passwords are stored only in hashed form. Proofs of payment are kept in private storage that staff
                    can only open through an access-controlled page, and each view is logged. Staff only see the events they're assigned to.
                    If we become aware of a breach that affects you, we will tell you and the Information Regulator as POPIA requires.
                </p>
            </section>

            <section id="rights">
                <h2>9. Your rights</h2>
                <p>You may:</p>
                <ul>
                    <li><strong>see what we hold</strong>: download it yourself from your <Link :href="route('attendee.preferences.edit')">Account page</Link>, or ask us;</li>
                    <li><strong>correct</strong> details that are wrong;</li>
                    <li><strong>delete</strong> your account (Account page), subject to the records in section 7;</li>
                    <li><strong>object</strong> to processing, and <strong>withdraw consent</strong> for networking or marketing at any time on your Account page. This doesn't affect processing already done.</li>
                </ul>
            </section>

            <section id="cookies">
                <h2>10. Cookies and browser storage</h2>
                <p>
                    We use two cookies, both needed for the site to work: one keeps you signed in, the other protects forms against forgery.
                    While you fill in the registration form, your browser keeps an unfinished copy for that tab only (not your consent choices), so a
                    reload doesn't lose it. We don't use advertising or analytics cookies. The chat window and the livestream providers may set their own
                    cookies when you use them.
                </p>
            </section>

            <section id="marketing">
                <h2>11. Direct marketing</h2>
                <p>
                    We only send you news about other events, resources or our services if you ticked the box asking for it. You can withdraw
                    at any time on your Account page. Event emails about something you registered for (confirmations, reminders, changes)
                    aren't marketing and don't need that consent.
                </p>
            </section>

            <section id="children">
                <h2>12. Children</h2>
                <p>Our events are for professionals. The platform isn't meant for anyone under 18, and we don't knowingly collect children's information.</p>
            </section>

            <section id="changes">
                <h2>13. Changes to this policy</h2>
                <p>
                    If we change this policy in a way that matters, we'll update the version and date above and ask you to review it the next time you
                    sign in. The <Link :href="route('legal.terms')">Terms &amp; Conditions</Link> cover the rules for using the platform.
                </p>
            </section>

            <section id="contact">
                <h2>14. Contact and complaints</h2>
                <p>
                    Questions or requests: <span :class="{ todo: todo(facts.information_officer_email) }">{{ facts.information_officer_email }}</span>
                    (Information Officer) or <span :class="{ todo: todo(facts.contact_email) }">{{ facts.contact_email }}</span>.
                </p>
                <p>
                    If you're not satisfied with our answer, you can complain to the <strong>Information Regulator (South Africa)</strong>:
                    <a href="https://inforegulator.org.za" target="_blank" rel="noopener">inforegulator.org.za</a>.
                </p>
            </section>
        </LegalDocument>
    </PublicLayout>
</template>
