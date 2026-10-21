<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';

// `stage` puts the header on the navy field so it merges with a stage hero
// (the landing page); `paper` is the plain header for forms and notices.
const props = defineProps({
    tone: { type: String, default: 'paper' }, // paper | stage
});

const page = usePage();
const onStage = computed(() => props.tone === 'stage');

const account = computed(() => {
    const user = page.props.auth?.user;
    if (user?.isStaff) return { href: '/admin', label: 'Admin' };
    if (user) return { href: '/dashboard', label: 'My dashboard' };
    return { href: '/login', label: 'Sign in' };
});

const organiser = computed(() => page.props.seminar?.organiserName ?? 'Lumko Business Technology Partners');

// Tawk.to chat widget. Only on the pages where a visitor might want to ask
// something: not on the registration stepper (its bubble covered the pinned
// "Next" button on phones), sign-in/password pages or error pages. Inertia
// keeps the widget alive across visits, so it is hidden again whenever a
// page without it (or any non-public layout) is shown.
const CHAT_PAGES = ['Public/Home', 'Public/RegisterConfirmation', 'Public/RegistrationClosed'];

function hideChat() {
    window.Tawk_API?.hideWidget?.();
}

function showChat() {
    if (document.getElementById('tawk-script')) {
        window.Tawk_API?.showWidget?.();
        return;
    }

    window.Tawk_API = window.Tawk_API || {};
    window.Tawk_LoadStart = new Date();
    // Keep the bubble clear of anything pinned to the bottom edge on phones.
    window.Tawk_API.customStyle = { visibility: { mobile: { position: 'br', xOffset: 12, yOffset: 88 } } };
    // The visitor may have moved on (e.g. to /attend) while it was loading.
    window.Tawk_API.onLoad = () => {
        if (!CHAT_PAGES.includes(page.component)) hideChat();
    };

    const script = document.createElement('script');
    script.id = 'tawk-script';
    script.async = true;
    script.src = 'https://embed.tawk.to/6aba8d747db1f83440f0f6e7/1k3kbh4v6';
    script.charset = 'UTF-8';
    script.setAttribute('crossorigin', '*');
    document.head.appendChild(script);
}

onMounted(() => (CHAT_PAGES.includes(page.component) ? showChat() : hideChat()));
onUnmounted(hideChat);
</script>
<template>
    <div class="flex min-h-screen flex-col bg-white text-ink-900">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:bg-white focus:px-3 focus:py-2 focus:text-ink-900"
        >
            Skip to content
        </a>

        <header :class="onStage ? 'stage' : 'border-b border-rule bg-white'">
            <div class="shell flex items-center justify-between gap-6 py-4 sm:py-5">
                <Link href="/" class="shrink-0" aria-label="Lumko home">
                    <img :src="onStage ? '/images/logo-light.svg' : '/images/logo.svg'" width="3141" height="854" alt="Lumko" class="h-7 w-auto sm:h-8" />
                </Link>

                <nav v-if="$slots.nav" aria-label="On this page" class="hidden flex-1 justify-center lg:flex">
                    <slot name="nav" />
                </nav>

                <Link
                    :href="account.href"
                    class="inline-flex min-h-10 items-center gap-2 border-b text-sm font-medium transition-colors"
                    :class="onStage
                        ? 'border-white/30 text-white hover:border-white'
                        : 'border-ink-200 text-ink-900 hover:border-ink-900'"
                >
                    {{ account.label }}
                </Link>
            </div>
        </header>

        <main id="main" class="flex-1">
            <slot />
        </main>

        <footer class="stage">
            <slot name="close" />
            <div class="shell grid gap-6 border-t border-white/15 py-8 text-meta text-white/65 sm:grid-cols-12 sm:items-center">
                <div class="sm:col-span-4">
                    <img src="/images/logo-light.svg" width="3141" height="854" alt="Lumko" class="h-7 w-auto" />
                </div>
                <p class="sm:col-span-5">
                    Hosted by {{ organiser }}.
                    <span class="block text-white/60">Expert seminars, executive breakfasts and forums.</span>
                </p>
                <p class="flex flex-wrap gap-x-5 gap-y-2 sm:col-span-3 sm:justify-end">
                    <span class="tnum">&copy; {{ new Date().getFullYear() }}</span>
                    <a :href="page.props.seminar?.privacyNoticeUrl || route('legal.privacy')" class="link">Privacy Policy</a>
                    <a :href="route('legal.terms')" class="link">Terms &amp; Conditions</a>
                </p>
            </div>
        </footer>
   
    </div>
</template>
