// Section tabs for the speaker and partner portals (PortalLayout `nav`).

export function speakerNav() {
    const onProfile = route().current('speaker.profile.*');

    return [
        // Every per-event page (stage, green room, resources, report) is
        // reached from the schedule, so it stays the current tab there.
        { label: 'Schedule', href: route('speaker.dashboard'), active: !onProfile },
        { label: 'Profile', href: route('speaker.profile.edit'), active: onProfile },
    ];
}

/**
 * The partner portal's tabs. On a page for one event (`event` given) the
 * tabs are that event's sections, limited to what the tier unlocks; on the
 * partner-wide pages (overview, profile, team) only those three.
 */
export function partnerNav(partner, event = null, features = []) {
    const has = (f) => features.includes(f);
    const partnerItem = (label, name, match) => ({
        label,
        href: route(name, partner.slug),
        active: route().current(match),
    });
    const eventItem = (label, name, match) => ({
        label,
        href: route(name, [partner.slug, event.id]),
        active: route().current(match),
    });

    return [
        partnerItem('Overview', 'partner.dashboard', 'partner.dashboard'),
        ...(event
            ? [
                  eventItem('Leads', 'partner.events.leads.index', 'partner.events.leads.*'),
                  ...(has('leads_scan') ? [eventItem('Scan', 'partner.events.scan.show', 'partner.events.scan.*')] : []),
                  ...(has('referrals') ? [eventItem('Referrals', 'partner.events.referrals', 'partner.events.referrals')] : []),
                  ...(has('analytics') ? [eventItem('Analytics', 'partner.events.analytics', 'partner.events.analytics')] : []),
                  ...(has('resources') ? [eventItem('Resources', 'partner.events.resources.index', 'partner.events.resources.*')] : []),
                  ...(has('comp_tickets') ? [eventItem('Tickets', 'partner.events.tickets.index', 'partner.events.tickets.*')] : []),
              ]
            : []),
        partnerItem('Profile', 'partner.profile.edit', 'partner.profile.*'),
        partnerItem('Team', 'partner.team.index', 'partner.team.*'),
    ];
}
