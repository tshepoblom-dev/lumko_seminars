import { test, expect } from '@playwright/test';
import { ADMIN, ATTENDEE, INVITEE, PARTNER, expectNoViolations, login, settle } from './support';

// The partner portal (E2E Partner Co, Gold tier with every feature, one
// seeded lead from the online attendee), the attendee's partner page, and
// a stand scan of the in-person attendee's ticket (code E2ETEST1).

const SHOTS = process.env.PORTAL_SHOTS_DIR;
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }) : null);
const PORTAL = /\/partner\/e2e-partner-co$/;

test('a partner signs in and sees its events, leads and pages', async ({ page }) => {
    await login(page, PARTNER, PORTAL);
    await settle(page);

    await expect(page.getByRole('heading', { level: 1, name: 'E2E Partner Co' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 2, name: 'The Power of Data' })).toBeVisible();
    await shot(page, 'partner-dashboard');
    await expectNoViolations(page, 'partner dashboard');

    await page.getByRole('link', { name: 'Your leads' }).click();
    await settle(page);
    await expect(page.getByText('E2E Attendee')).toBeVisible();
    await expect(page.getByText('Interested in your data platform')).toBeVisible();
    await shot(page, 'partner-leads');
    await expectNoViolations(page, 'partner leads');

    for (const [tab, heading, name] of [
        ['Referrals', 'Referrals', 'partner-referrals'],
        ['Analytics', 'Analytics', 'partner-analytics'],
        ['Resources', 'Resources and offers', 'partner-resources'],
        ['Tickets', 'Complimentary tickets', 'partner-tickets'],
        ['Profile', 'Your profile', 'partner-profile'],
        ['Team', 'Your team', 'partner-team'],
    ]) {
        await page.getByRole('navigation', { name: /Partner portal/ }).getByRole('link', { name: tab, exact: true }).click();
        // Wait for the new page itself, so the scan and screenshot aren't of the previous one.
        await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible();
        await settle(page);
        await shot(page, name);
        await expectNoViolations(page, `partner ${tab}`);
    }
});

test('a stand scan: the attendee agrees on the rep\'s screen and becomes a lead', async ({ page }) => {
    await login(page, PARTNER, PORTAL);
    await page.getByRole('link', { name: 'Scan at your stand' }).click();
    await settle(page);
    await expect(page.getByText('Your stand QR')).toBeVisible();
    await shot(page, 'partner-scan');
    await expectNoViolations(page, 'partner scan');

    await page.getByLabel('Or type the ticket code').fill('E2ETEST1');
    await page.getByRole('button', { name: 'Look up' }).click();

    await expect(page.getByText('Please hand this screen to the attendee')).toBeVisible();
    await expect(page.getByRole('heading', { name: /^Hi E2E/ })).toBeVisible();
    // Nothing identifying beyond first name and organisation.
    await expect(page.getByText('e2e-checkin@example.test')).toHaveCount(0);
    await shot(page, 'partner-scan-ask');
    await expectNoViolations(page, 'partner scan, attendee consent screen');

    await page.getByRole('button', { name: 'Yes, share my details' }).click();
    await expect(page.getByText('They’re now in your leads')).toBeVisible();

    await page.getByRole('navigation', { name: /Partner portal/ }).getByRole('link', { name: 'Leads', exact: true }).click();
    await settle(page);
    await expect(page.locator('li', { hasText: 'E2E CheckIn' })).toContainText('Stand scan');
});

test('attendees see the partner, connect, and the registration form offers it as a referral', async ({ page, browser }) => {
    // Already connected (seeded).
    await login(page, ATTENDEE, /\/dashboard/);
    await settle(page);
    await page.getByRole('link', { name: 'E2E Partner Co' }).first().click();
    await settle(page);
    await expect(page.getByRole('heading', { name: "You're connected" })).toBeVisible();
    await shot(page, 'attendee-partner-connected');
    await expectNoViolations(page, 'partner page, connected');

    // Another attendee connects, after reading the exact sentence.
    const ctx = await browser.newContext();
    const other = await ctx.newPage();
    await login(other, INVITEE, /\/dashboard/);
    await other.goto(page.url());
    await settle(other);
    await other.getByRole('button', { name: 'Connect with E2E Partner Co' }).click();
    await expect(other.getByRole('dialog')).toContainText('a partner of this event');
    await shot(other, 'attendee-partner-connect-modal');
    await other.getByRole('button', { name: 'Yes, share my details' }).click();
    await expect(other.getByRole('heading', { name: "You're connected" })).toBeVisible();
    await ctx.close();

    // A guest following the partner's referral link finds it pre-selected.
    const guestCtx = await browser.newContext();
    const guest = await guestCtx.newPage();
    await guest.goto('/attend?ref=e2e-partner-co');
    await settle(guest);
    await guest.getByLabel('First name').fill('Referral');
    await guest.getByLabel('Last name').fill('Guest');
    await guest.getByLabel('Email').fill('e2e-referral@example.test');
    await guest.getByRole('button', { name: 'Next' }).click();
    // A tiled radio list: general sources with icons, then the partners.
    const tiles = guest.getByRole('group', { name: 'How did you hear about us?' });
    await expect(tiles.getByRole('radio', { name: /^E2E Partner Co/ })).toBeChecked();
    await expect(tiles.getByRole('radio', { name: 'LinkedIn' })).not.toBeChecked();
    await expect(guest.getByText('Telling us who sent you doesn\'t share anything with them')).toBeVisible();
    await shot(guest, 'register-referral');

    // Switching to a general source hides the share box; back to the partner shows it again.
    await tiles.getByRole('radio', { name: 'LinkedIn' }).check();
    await expect(guest.getByText('Telling us who sent you doesn\'t share anything with them')).toHaveCount(0);
    await tiles.getByRole('radio', { name: /^E2E Partner Co/ }).check();
    await expect(guest.getByText('Telling us who sent you doesn\'t share anything with them')).toBeVisible();
    await guest.setViewportSize({ width: 375, height: 800 });
    await shot(guest, 'register-referral-phone');
    expect(await guest.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await guest.setViewportSize({ width: 1280, height: 800 });
    await expectNoViolations(guest, 'registration step 2 with a referral');
    await guestCtx.close();
});

test('staff invite, resend and cancel a partner team invitation, then open the portal', async ({ page }) => {
    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/partners/e2e-partner-co/edit#portal');
    await settle(page);

    const team = page.locator('section#portal');
    await expect(team.getByRole('heading', { name: 'Portal team' })).toBeVisible();
    await expect(team.locator('li', { hasText: 'e2e-partner@example.test' })).toContainText('Owner');

    // Invite: the email and role are typed in the confirmation itself.
    const email = `e2e-partner-team-${Date.now()}@example.test`;
    await team.getByRole('button', { name: 'Invite someone' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText("Invite someone to E2E Partner Co's portal");
    await expect(dialog.getByLabel('Role')).toHaveValue('member'); // the partner already has an owner
    await dialog.getByLabel('Their email').fill(email);
    await expect(dialog).toContainText('as a team member');
    await expectNoViolations(page, 'invite partner team member modal');
    await dialog.getByRole('button', { name: 'Send invitation' }).click();

    await expect(page.getByText(`Invitation to E2E Partner Co's portal emailed to ${email}`)).toBeVisible();
    const pending = team.locator('li', { hasText: email });
    await expect(pending).toContainText('Team member · sent');
    await expectNoViolations(page, 'partner edit, portal team with a pending invitation');

    // Resend: a fresh link, the old one stops working.
    await pending.getByRole('button', { name: `Resend to ${email}` }).click();
    await expect(dialog).toContainText('Resend the partner portal invitation');
    await expect(dialog).toContainText('The earlier invitation stops working.');
    await dialog.getByRole('button', { name: 'Resend invitation' }).click();
    await expect(page.getByText(`Invitation resent to ${email}`)).toBeVisible();

    // Cancel, after a browser confirmation.
    page.once('dialog', (d) => d.accept());
    await team.locator('li', { hasText: email }).getByRole('button', { name: `Cancel the invitation to ${email}` }).click();
    await expect(page.getByText(`The invitation to ${email} no longer works.`)).toBeVisible();
    await expect(team.locator('li', { hasText: email })).toHaveCount(0);

    // Super admins can open the portal; it says they're there as staff.
    await team.getByRole('link', { name: 'Open portal' }).click();
    await settle(page);
    await expect(page.getByRole('heading', { level: 1, name: 'E2E Partner Co' })).toBeVisible();
    await expect(page.locator('[data-staff-view]')).toContainText("You're viewing E2E Partner Co's portal as Lumko staff.");
    await expectNoViolations(page, 'partner portal, staff view');

    // And it leads straight back to the partner's team in admin.
    await page.getByRole('link', { name: 'Back to admin' }).click();
    await settle(page);
    await expect(page.locator('section#portal')).toBeVisible();
});

test('the event Partners page shows each partner\'s portal state', async ({ page }) => {
    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/events');
    await settle(page);
    const href = await page.locator('a[href*="/admin/events/"][href$="/edit"]').first().getAttribute('href');
    const eventId = href.match(/events\/(\d+)\//)[1];

    await page.goto(`/admin/events/${eventId}/partners`);
    await settle(page);
    const row = page.locator('tr', { hasText: 'E2E Partner Co' });
    await expect(row.getByText(/^1 person/)).toBeVisible();
    await expect(row.getByRole('link', { name: 'Team for E2E Partner Co' })).toBeVisible();
    await expect(row.getByRole('link', { name: 'Open portal for E2E Partner Co' })).toBeVisible();

    await row.getByRole('button', { name: 'Invite someone from E2E Partner Co' }).click();
    await expect(page.getByRole('dialog')).toContainText("Invite someone to E2E Partner Co's portal");
    await expectNoViolations(page, 'event partners, invite modal');
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
    await expectNoViolations(page, 'event partners with portal states');
});
