import { usePage } from '@inertiajs/vue3';

/**
 * Admin pages inside an event workspace (/admin/events/{event}/…) build
 * their links with the workspace's event filled in, so a page only names
 * the record it's about:
 *
 *   const eventRoute = useEventRoute();
 *   eventRoute('admin.programme-sessions.edit', { programmeSession: id });
 *
 * The event comes from the `workspace` prop shared by ShareEventWorkspace.
 */
export function useEventRoute() {
    const page = usePage();

    return (name, params = {}) => route(name, { event: page.props.workspace?.event?.id, ...params });
}
