<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FlashMessages from '@/Components/FlashMessages.vue';
import EventWorkspaceNav from '@/Components/Admin/EventWorkspaceNav.vue';
import PortalSwitcher from '@/Components/PortalSwitcher.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const menuOpen = ref(false);
const eventMenuOpen = ref(false);

// Pages under /admin/events/{event} get the event workspace: a header
// naming the event, a switcher, and the event's own sidebar. The data is
// shared by the ShareEventWorkspace middleware.
const workspace = computed(() => page.props.workspace ?? null);

// Only the cross-event areas live in the top nav; everything that belongs
// to one event is in that event's workspace sidebar.
const nav = computed(() => [
    { label: 'Dashboard', route: 'admin.dashboard', match: 'admin.dashboard' },
    { label: 'Events', route: 'admin.events.index', match: 'admin.events.*' },
    { label: 'Speakers', route: 'admin.speakers.index', match: 'admin.speakers.*' },
    { label: 'Partners', route: 'admin.partners.index', match: 'admin.partners.*' },
    { label: 'Analytics', route: 'admin.analytics.index', match: 'admin.analytics.*' },
    ...(user.value?.isSuperAdmin
        ? [
              // Platform-wide email; per-event email is in each event's workspace.
              { label: 'Communications', route: 'admin.platform-communications.index', match: 'admin.platform-communications.*' },
              { label: 'Audit', route: 'admin.audit.index', match: 'admin.audit.*' },
          ]
        : []),
]);

const isActive = (item) => {
    // Inside a workspace, "Events" is the active area (but not the
    // library's /admin/speakers, which shares no prefix with it).
    if (item.label === 'Events') return route().current('admin.events.*') || !!workspace.value;
    return route().current(item.match);
};

const statusLabel = (status) =>
    ({ draft: 'Draft', published: 'Published', completed: 'Completed', archived: 'Archived' })[status] ?? status;

const statusClass = (status) =>
    ({
        published: 'bg-green-100 text-green-800',
        completed: 'bg-blue-100 text-blue-800',
        archived: 'bg-gray-200 text-gray-700',
    })[status] ?? 'bg-amber-100 text-amber-900';

const formatDate = (iso) =>
    iso
        ? new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Africa/Johannesburg' })
        : '';

// Switching events keeps you on the same kind of page (Payments stays
// Payments) when that page is a list; from a single record (one payment,
// one session) there's no equivalent, so it lands on the Overview.
function switchEvent(eventId) {
    const current = route().current();
    const params = route().params ?? {};
    const onList = current && Object.keys(params).every((key) => key === 'event');

    router.visit(route(onList ? current : 'admin.events.show', { event: eventId }));
}
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"
        >
            Skip to content
        </a>

        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8">
                <Link :href="route('admin.dashboard')" class="text-lg font-semibold text-brand">
                    Lumko Seminars <span class="font-normal text-gray-500">· Admin</span>
                </Link>

                <div class="flex items-center gap-4 text-sm">
                    <a :href="route('home')" class="hidden text-gray-600 hover:text-gray-900 sm:inline">View site</a>
                    <PortalSwitcher current="admin" :on-stage="false" />
                    <Link :href="route('profile.edit')" class="hidden text-gray-600 hover:text-gray-900 hover:underline sm:inline" title="Profile &amp; password">{{ user?.name }}</Link>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="whitespace-nowrap rounded border border-gray-300 px-3 py-1.5 text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                    >
                        Log out
                    </Link>
                    <button
                        type="button"
                        class="rounded border border-gray-300 px-3 py-1.5 text-gray-700 sm:hidden"
                        :aria-expanded="menuOpen"
                        aria-controls="admin-nav"
                        @click="menuOpen = !menuOpen"
                    >
                        Menu
                    </button>
                </div>
            </div>

            <nav
                id="admin-nav"
                aria-label="Admin"
                :class="[menuOpen ? 'block' : 'hidden', 'border-t border-gray-100 sm:block']"
            >
                <ul class="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-2 sm:flex-row sm:px-6 lg:px-8">
                    <li v-for="item in nav" :key="item.label">
                        <Link
                            :href="route(item.route)"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            :class="[
                                'block rounded px-3 py-1.5 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-brand',
                                isActive(item)
                                    ? 'bg-brand text-white'
                                    : 'text-gray-700 hover:bg-gray-100',
                            ]"
                        >
                            {{ item.label }}
                        </Link>
                    </li>
                </ul>
            </nav>
        </header>

        <!-- Event workspace -->
        <template v-if="workspace">
            <div class="border-b border-gray-200 bg-white">
                <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8">
                    <div class="min-w-0">
                        <Link :href="route('admin.events.index')" class="text-xs font-medium text-gray-600 hover:text-gray-900">
                            All events
                        </Link>
                        <div class="mt-0.5 flex flex-wrap items-center gap-2">
                            <p class="truncate text-lg font-semibold text-gray-900">{{ workspace.event.name }}</p>
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-medium', statusClass(workspace.event.status)]">
                                {{ statusLabel(workspace.event.status) }}
                            </span>
                            <span v-if="workspace.event.is_live" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                Live now
                            </span>
                        </div>
                        <p class="text-sm text-gray-600">{{ formatDate(workspace.event.starts_at) }}</p>
                    </div>

                    <div v-if="workspace.events.length > 1" class="flex items-center gap-2">
                        <label for="workspace-event-switcher" class="text-sm text-gray-600">Switch event</label>
                        <select
                            id="workspace-event-switcher"
                            :value="workspace.event.id"
                            class="max-w-[16rem] rounded border-gray-300 text-sm"
                            @change="switchEvent(Number($event.target.value))"
                        >
                            <option v-for="e in workspace.events" :key="e.id" :value="e.id">
                                {{ e.name }} · {{ formatDate(e.starts_at) }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:flex lg:gap-8 lg:px-8">
                <aside class="pt-4 lg:w-52 lg:shrink-0 lg:pt-6">
                    <button
                        type="button"
                        class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-left text-sm text-gray-700 lg:hidden"
                        :aria-expanded="eventMenuOpen"
                        aria-controls="event-nav"
                        @click="eventMenuOpen = !eventMenuOpen"
                    >
                        Event menu
                    </button>
                    <div id="event-nav" :class="[eventMenuOpen ? 'block' : 'hidden', 'mt-2 rounded bg-white p-2 lg:sticky lg:top-4 lg:mt-0 lg:block lg:bg-transparent lg:p-0']">
                        <EventWorkspaceNav :event="workspace.event" />
                    </div>
                </aside>

                <div class="min-w-0 flex-1">
                    <div v-if="$slots.header" class="pt-6">
                        <slot name="header" />
                    </div>

                    <div class="pt-4">
                        <FlashMessages />
                    </div>

                    <main id="main" class="workspace-main">
                        <slot />
                    </main>
                </div>
            </div>
        </template>

        <!-- Cross-event pages -->
        <template v-else>
            <div v-if="$slots.header" class="bg-white shadow">
                <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </div>

            <div class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8">
                <FlashMessages />
            </div>

            <main id="main">
                <slot />
            </main>
        </template>
    </div>
</template>
