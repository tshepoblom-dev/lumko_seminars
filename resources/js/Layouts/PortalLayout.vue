<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import FlashMessages from '@/Components/FlashMessages.vue';
import PortalSwitcher from '@/Components/PortalSwitcher.vue';

// The speaker and partner portals: the attendee area's stage header and
// section tabs, with the portal's own sections. Each page passes its nav.
const props = defineProps({
    area: { type: String, required: true }, // shown beside the logo: "Speaker portal", a partner's name
    current: { type: String, required: true }, // PortalSwitcher key: speaker | partner:{slug}
    homeHref: { type: String, required: true },
    nav: { type: Array, required: true }, // [{ label, href, active }]
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-white text-ink-900">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:bg-white focus:px-3 focus:py-2 focus:text-ink-900"
        >
            Skip to content
        </a>

        <slot name="notice" />

        <header class="stage no-print">
            <div class="shell flex items-center justify-between gap-4 py-4">
                <Link :href="homeHref" class="flex min-w-0 shrink items-center gap-3" :aria-label="`Lumko Seminars, ${area}`">
                    <img src="/images/logo-light.svg" width="3141" height="854" alt="" class="h-7 w-auto shrink-0" />
                    <span class="truncate border-l border-white/20 pl-3 text-meta text-white/60">{{ area }}</span>
                </Link>

                <div class="flex shrink-0 items-center gap-2 text-sm sm:gap-4">
                    <PortalSwitcher :current="current" />
                    <span class="hidden max-w-[12rem] truncate text-white/65 lg:inline">{{ user?.name }}</span>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="inline-flex min-h-10 items-center rounded-ctl border border-white/30 px-3 text-white transition-colors hover:border-white focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-400"
                    >
                        Log out
                    </Link>
                </div>
            </div>

            <nav :aria-label="area" class="border-t border-white/10">
                <ul class="shell flex gap-6 overflow-x-auto [scrollbar-width:none] sm:gap-8 [&::-webkit-scrollbar]:hidden">
                    <li v-for="item in nav" :key="item.href" class="shrink-0">
                        <Link
                            :href="item.href"
                            :aria-current="item.active ? 'page' : undefined"
                            :class="[
                                'block border-b-2 py-3 text-sm transition-colors focus:outline-none focus-visible:text-white focus-visible:underline',
                                item.active ? 'border-signal-500 font-medium text-white' : 'border-transparent text-white/60 hover:text-white',
                            ]"
                        >
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>
            </nav>
        </header>

        <div class="shell no-print">
            <FlashMessages />
        </div>

        <main id="main" class="flex-1">
            <slot />
        </main>

        <footer class="stage no-print">
            <div class="shell flex flex-wrap items-center justify-between gap-4 py-6 text-meta text-white/60">
                <span>{{ page.props.seminar?.organiserName ?? 'Lumko Business Technology Partners' }}</span>
                <span class="flex gap-5">
                    <a :href="page.props.seminar?.privacyNoticeUrl || route('legal.privacy')" class="link">Privacy Policy</a>
                    <a :href="route('legal.terms')" class="link">Terms &amp; Conditions</a>
                </span>
            </div>
        </footer>
    </div>
</template>
