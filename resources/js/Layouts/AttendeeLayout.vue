<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FlashMessages from '@/Components/FlashMessages.vue';
import PortalSwitcher from '@/Components/PortalSwitcher.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const notifications = computed(() => page.props.notifications);
const bellOpen = ref(false);
const bellRoot = ref(null);

// D-Layouts — the same nav everywhere so the public site and the
// attendee portal feel like one product.
const nav = [
    { label: 'Dashboard', route: 'attendee.dashboard', match: 'attendee.dashboard' },
    { label: 'Live', route: 'live.show', match: 'live.show' },
    { label: 'Resources', route: 'resources.index', match: 'resources.*' },
    { label: 'Networking', route: 'networking.index', match: 'networking.*' },
    { label: 'Feedback', route: 'feedback.create', match: 'feedback.*' },
    { label: 'Account', route: 'attendee.preferences.edit', match: 'attendee.preferences.*' },
    { label: 'Profile & password', route: 'profile.edit', match: 'profile.*' },
];

const isActive = (item) => route().current(item.match);

function markRead(id) {
    router.post(route('notifications.read', id), {}, { preserveScroll: true, preserveState: true });
}

function markAllRead() {
    router.post(route('notifications.read-all'), {}, { preserveScroll: true, preserveState: true });
    bellOpen.value = false;
}

// Close the notifications panel on Escape or an outside click.
function onDocClick(e) {
    if (bellOpen.value && bellRoot.value && !bellRoot.value.contains(e.target)) bellOpen.value = false;
}
function onKey(e) {
    if (e.key === 'Escape') bellOpen.value = false;
}
onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div class="flex min-h-screen flex-col bg-white text-ink-900">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:bg-white focus:px-3 focus:py-2 focus:text-ink-900"
        >
            Skip to content
        </a>

        <header class="stage no-print">
            <div class="shell flex items-center justify-between gap-4 py-4">
                <Link :href="route('attendee.dashboard')" class="flex shrink-0 items-center gap-3" aria-label="Lumko Seminars, your dashboard">
                    <img src="/images/logo-light.svg" width="3141" height="854" alt="" class="h-7 w-auto" />
                    <span class="hidden border-l border-white/20 pl-3 text-meta text-white/60 sm:inline">Seminars</span>
                </Link>

                <div class="flex items-center gap-2 text-sm sm:gap-4">
                    <a :href="route('home')" class="hidden text-white/65 transition-colors hover:text-white md:inline">Public site</a>

                    <div v-if="notifications" ref="bellRoot" class="relative">
                        <button
                            type="button"
                            class="relative flex h-10 w-10 items-center justify-center rounded-ctl text-white/80 transition-colors hover:bg-white/10 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-400"
                            :aria-expanded="bellOpen"
                            aria-label="Notifications"
                            @click="bellOpen = !bellOpen"
                        >
                            <svg aria-hidden="true" viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path d="M10 2.75a4.75 4.75 0 0 0-4.75 4.75v2.3c0 .5-.16.98-.46 1.38L3.75 12.6v1.15h12.5V12.6l-1.04-1.42a2.3 2.3 0 0 1-.46-1.38V7.5A4.75 4.75 0 0 0 10 2.75Z" />
                                <path d="M8 16.25a2 2 0 0 0 4 0" />
                            </svg>
                            <span
                                v-if="notifications.unreadCount > 0"
                                class="tnum absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-signal-500 px-1 text-[10px] font-semibold text-white"
                            >
                                {{ notifications.unreadCount }}
                            </span>
                        </button>

                        <div
                            v-if="bellOpen"
                            class="absolute right-0 z-30 mt-2 w-[min(20rem,calc(100vw-2rem))] border-t-2 border-ink-900 bg-white text-ink-900 shadow-float"
                        >
                            <div class="flex items-center justify-between border-b border-rule px-4 py-3">
                                <p class="eyebrow text-ink-500">Notifications</p>
                                <button v-if="notifications.unreadCount > 0" class="link text-meta" @click="markAllRead">
                                    Mark all read
                                </button>
                            </div>
                            <ul class="max-h-80 overflow-y-auto">
                                <li
                                    v-for="n in notifications.recent"
                                    :key="n.id"
                                    class="cursor-pointer border-b border-rule px-4 py-3 text-sm last:border-0 hover:bg-paper"
                                    :class="{ 'bg-signal-50/60 font-medium': !n.read }"
                                    @click="markRead(n.id)"
                                >
                                    <p>{{ n.message }}</p>
                                    <p class="mt-0.5 text-meta font-normal text-ink-500">{{ n.created_at }}</p>
                                </li>
                                <li v-if="!notifications.recent.length" class="px-4 py-8 text-center text-sm text-ink-500">
                                    No notifications yet.
                                </li>
                            </ul>
                        </div>
                    </div>

                    <PortalSwitcher current="attendee" />

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

            <!-- Section tabs: underline marks the current page; scrolls sideways on phones. -->
            <nav id="attendee-nav" aria-label="Attendee" class="border-t border-white/10">
                <ul class="shell flex gap-6 overflow-x-auto [scrollbar-width:none] sm:gap-8 [&::-webkit-scrollbar]:hidden">
                    <li v-for="item in nav" :key="item.route" class="shrink-0">
                        <Link
                            :href="route(item.route)"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            :class="[
                                'block border-b-2 py-3 text-sm transition-colors focus:outline-none focus-visible:text-white focus-visible:underline',
                                isActive(item) ? 'border-signal-500 font-medium text-white' : 'border-transparent text-white/60 hover:text-white',
                            ]"
                        >
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>
            </nav>
        </header>

        <div v-if="$slots.header" class="border-b border-rule bg-white">
            <div class="shell py-5">
                <slot name="header" />
            </div>
        </div>

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
