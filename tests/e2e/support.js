import path from 'node:path';
import { expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

export const ADMIN = { email: 'admin@lumko.cloud', password: 'password' };
export const ATTENDEE = { email: 'e2e-attendee@example.test', password: 'Password123!' };
export const IN_PERSON_PAID = { email: 'e2e-checkin@example.test', password: 'Password123!' };
export const IN_PERSON_PENDING = { email: 'e2e-pending@example.test', password: 'Password123!' };
export const UNVERIFIED = { email: 'e2e-unverified@example.test', password: 'Password123!' };
// Registered online; switches to in person when the event goes in person only.
export const SWITCHER = { email: 'e2e-switcher@example.test', password: 'Password123!' };
// A confirmed account with no registration for the event.
export const ACCOUNT_ONLY = { email: 'e2e-accountonly@example.test', password: 'Password123!' };
export const NO_TERMS ={ email: 'e2e-noterms@example.test', password: 'Password123!' };
// Registered online; invitation code E2E0-VCHR-0001 is reserved for this address.
export const INVITEE = { email: 'e2e-invitee@example.test', password: 'Password123!' };
// Linked to the event's first speaker (speaker portal).
export const SPEAKER = { email: 'e2e-speaker@example.test', password: 'Password123!' };
// Owner of "E2E Partner Co" (slug e2e-partner-co), Gold tier, every feature.
export const PARTNER = { email: 'e2e-partner@example.test', password: 'Password123!' };

// Wait until the page has rendered. `networkidle` alone is unreliable: the app
// calls Vite::prefetch(), so the browser keeps fetching every lazy chunk in the
// background and, on the single-threaded dev server, the network is never quiet
// for 500 ms. Wait for the Inertia app to mount, then give the network a short,
// best-effort chance to settle.
export async function settle(page) {
    await page.waitForLoadState('load');
    await page.locator('#app > *').first().waitFor({ state: 'attached' });
    await page.waitForLoadState('networkidle', { timeout: 2000 }).catch(() => {});
}

// WCAG 2.x A/AA rules only; "best-practice" rules are advisory.
export async function expectNoViolations(page, label) {
    await settle(page);

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    const summary = results.violations.map(
        (v) => `${v.id} (${v.impact}): ${v.help} — ${v.nodes.length} node(s), e.g. ${v.nodes[0]?.target}`,
    );
    expect(summary, `${label} axe violations`).toEqual([]);
}

export async function login(page, { email, password }, landing = /\/(admin|dashboard|verify-email)/) {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(landing);
}

// Scan a URL and fail with the label if axe reports anything.
export async function scan(page, url, label = url) {
    const response = await page.goto(url);
    expect(response?.status(), `${label} should load`).toBeLessThan(400);
    await expectNoViolations(page, label);
}

// One line per axe violation on the current page (WCAG 2.x A/AA).
export async function axeProblems(page) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();

    return results.violations.map(
        (v) => `${v.id} (${v.impact}) — ${v.help} [${v.nodes.length}] e.g. ${v.nodes[0]?.target}`,
    );
}

// Layout problems that only appear at narrow widths: the page scrolling
// sideways, or content silently cut off by an `overflow-hidden` ancestor
// (which axe can't see and which a user can't scroll to).
export async function layoutProblems(page) {
    return page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const label = (el) => {
            const classes = typeof el.className === 'string' ? el.className.trim().split(/\s+/).filter(Boolean).slice(0, 3) : [];

            return `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}${classes.length ? `.${classes.join('.')}` : ''}`;
        };
        const clipsX = (el) => ['auto', 'scroll', 'hidden', 'clip'].includes(getComputedStyle(el).overflowX);
        const problems = [];

        if (document.documentElement.scrollWidth > vw) {
            const offenders = [];
            for (const el of document.body.querySelectorAll('*')) {
                const rect = el.getBoundingClientRect();
                if (rect.width === 0 || rect.right <= vw + 1) continue;

                let clipped = false;
                for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
                    if (clipsX(parent) && parent.getBoundingClientRect().right <= vw + 1) {
                        clipped = true;
                        break;
                    }
                }
                if (!clipped) offenders.push(`${label(el)} (right edge ${Math.round(rect.right)}px)`);
            }
            problems.push(
                `page scrolls sideways (${document.documentElement.scrollWidth}px wide in a ${vw}px viewport): ${offenders.slice(0, 4).join('; ')}`,
            );
        }

        for (const el of document.body.querySelectorAll('*')) {
            // Form controls scroll a long value inside themselves; that is normal.
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) continue;
            const style = getComputedStyle(el);
            if (!['hidden', 'clip'].includes(style.overflowX) || style.textOverflow === 'ellipsis') continue;
            // clientWidth > 8 skips visually-hidden helpers (sr-only is 1px wide by design).
            if (el.clientWidth > 8 && el.scrollWidth > el.clientWidth + 1) {
                problems.push(`content cut off by ${label(el)} (${el.scrollWidth}px of content in ${el.clientWidth}px)`);
            }
        }

        return problems;
    });
}

// Scan many pages and return one line per problem, so a single run shows every
// failing page instead of stopping at the first.
//   layout: also report sideways scrolling / clipped content (responsive runs)
//   shots:  { dir, prefix, match } screenshots pages whose URL matches `match`
export async function scanAll(page, urls, { allowStatus = [], layout = false, shots = null } = {}) {
    const problems = [];

    for (const url of urls) {
        try {
            const response = await page.goto(url);
            const status = response?.status() ?? 0;
            if (status >= 400 && !allowStatus.includes(status)) {
                problems.push(`${url}: HTTP ${status}`);
                continue;
            }

            await settle(page);

            for (const problem of await axeProblems(page)) problems.push(`${url}: ${problem}`);

            if (layout) {
                for (const problem of await layoutProblems(page)) problems.push(`${url}: ${problem}`);
            }

            if (shots && shots.match.test(url)) {
                await screenshot(page, shots.dir, `${shots.prefix}-${url.replace(/[^a-z0-9]+/gi, '-')}`);
            }
        } catch (error) {
            problems.push(`${url}: ${String(error.message).split('\n')[0]}`);
        }
    }

    return problems;
}

// Screenshots go to a directory named by the caller (never the repo), and only
// when asked for: RESPONSIVE_SHOTS=<dir> npx playwright test responsive
export async function screenshot(page, dir, name) {
    if (!dir) return;
    await page.screenshot({ path: path.join(dir, `${name}.png`), fullPage: true });
}

// Every admin GET page, with the ids discovered from the index pages (ids in a
// fresh test database are deterministic, but discovering them is sturdier).
export async function adminUrls(page) {
    const eventEdit = await firstHref(page, '/admin/events', /\/admin\/events\/\d+\/edit$/);
    const eventId = eventEdit.match(/events\/(\d+)\//)[1];
    const speakerEdit = await firstHref(page, '/admin/speakers', /\/admin\/speakers\/\d+\/edit$/);
    const ws = `/admin/events/${eventId}`; // the event workspace
    const sessionEdit = await firstHref(page, `${ws}/sessions`, /\/sessions\/\d+\/edit$/);
    const announcementEdit = await firstHref(page, `${ws}/announcements`, /\/announcements\/\d+\/edit$/);
    const resourceEdit = await firstHref(page, `${ws}/resources`, /\/resources\/\d+\/edit$/);
    const paymentShow = await firstHref(page, `${ws}/payments`, /\/payments\/\d+$/);
    const attendeeShow = await firstHref(page, `/admin/events/${eventId}/attendees`, /\/attendees\/\d+$/);

    return [
        '/admin/events',
        '/admin/events/create',
        eventEdit,
        `/admin/events/${eventId}/attendees`,
        attendeeShow,
        `/admin/events/${eventId}/engagement`,
        '/admin/speakers',
        '/admin/speakers/create',
        speakerEdit,
        '/admin/partners',
        '/admin/partners/create',
        '/admin/partners/e2e-partner-co/edit',
        '/admin/audit',
        '/admin/analytics',
        '/admin/communications',
        '/admin/communications/create',
        '/profile',
        ws,
        `${ws}/speakers`,
        `${ws}/partners`,
        `${ws}/registration-form`,
        `${ws}/sessions`,
        `${ws}/sessions/create`,
        sessionEdit,
        `${ws}/payments`,
        paymentShow,
        `${ws}/vouchers`,
        `${ws}/check-in`,
        `${ws}/announcements`,
        `${ws}/announcements/create`,
        announcementEdit,
        `${ws}/communications`,
        `${ws}/communications/create`,
        `${ws}/communications/create?mode=selected`,
        `${ws}/resources`,
        `${ws}/resources/create`,
        resourceEdit,
        `${ws}/live/control-room`,
        `${ws}/live/questions`,
        `${ws}/live/polls`,
        `${ws}/live/polls/create`,
        `${ws}/live/broadcast`,
        `${ws}/feedback`,
    ];
}

// First link on an index page whose href matches the pattern (ids in a fresh
// test database are deterministic, but discovering them is sturdier).
export async function firstHref(page, indexUrl, pattern) {
    await page.goto(indexUrl);
    await settle(page);
    const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((a) => a.getAttribute('href')));
    const found = hrefs.find((href) => pattern.test(href));
    expect(found, `a link matching ${pattern} on ${indexUrl}`).toBeTruthy();

    return found;
}
