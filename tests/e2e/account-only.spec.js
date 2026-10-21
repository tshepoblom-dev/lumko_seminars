import { readFileSync } from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';
import { ACCOUNT_ONLY, ADMIN, expectNoViolations, login, settle } from './support';

// People with an account who aren't registered for the event (seeded:
// E2E AccountOnly, confirmed; E2E Unverified, not confirmed), and what
// happens when a guest registers an email that already has an account.

// The single-threaded dev server sometimes resets a connection while the
// browser is fetching scripts, and the app never boots. Load the page again
// when that happens (up to four tries) instead of waiting out the test.
async function visit(page, url) {
    for (let attempt = 1; attempt <= 4; attempt++) {
        const response = await page.goto(url, { timeout: 60_000 }).catch(() => null);
        if (response && response.status() >= 400) return response;
        try {
            await page.locator('#app > *').first().waitFor({ state: 'attached', timeout: 20_000 });
            await settle(page);

            return response;
        } catch {
            // try again
        }
    }

    throw new Error(`${url} never rendered`);
}

async function eventId(page) {
    await visit(page, '/admin/events');
    const href = await page.locator('a[href*="/admin/events/"][href$="/edit"]').first().getAttribute('href');

    return href.match(/events\/(\d+)\//)[1];
}

// Emails are written to the log (MAIL_MAILER=log). The plain-text part is
// quoted-printable: soft line breaks are "=\r\n" and "=" is "=3D".
function lastCancelLink() {
    const log = readFileSync(path.resolve('storage/logs/laravel.log'), 'utf8')
        .replace(/=\r?\n/g, '')
        .replace(/=3D/g, '=');
    const links = log.match(/https?:\/\/[^\s"<>]+\/registration-not-me\/[^\s"<>]+/g) ?? [];

    return links.at(-1)?.replace(/&amp;/g, '&');
}

test('admin sees accounts that never registered and reminds one', async ({ page }) => {
    await login(page, ADMIN, /\/admin/);
    const id = await eventId(page);

    await visit(page, `/admin/events/${id}/attendees`);

    const card = page.locator('section#accounts-only');
    await expect(card.getByRole('heading', { name: /Has an account, not registered yet \(\d+\)/ })).toBeVisible();
    const row = card.locator('tr', { hasText: ACCOUNT_ONLY.email });
    await expect(row).toContainText('E2E AccountOnly');
    await expect(row).toContainText('Confirmed');
    await expect(row).toContainText('Not sent');
    await expect(card.locator('tr', { hasText: 'e2e-unverified@example.test' })).toContainText('Not confirmed');
    // Not an attendee, so not in the list above.
    await expect(page.locator('table').first().getByText('E2E AccountOnly')).toHaveCount(0);
    await expectNoViolations(page, 'attendees, accounts not registered yet');

    await row.getByRole('button', { name: 'Send reminder to E2E AccountOnly' }).click();
    await expect(page.getByText(`Reminder to register sent to ${ACCOUNT_ONLY.email}.`)).toBeVisible();
    await expect(card.locator('tr', { hasText: ACCOUNT_ONLY.email })).toContainText(/Sent \d+ \w+ \d{4}/);

    await card.getByRole('button', { name: 'Remind all to register' }).click();
    await expect(page.getByRole('dialog')).toContainText('Anyone reminded in the last 24 hours is skipped.');
    await expectNoViolations(page, 'attendees, remind all modal');
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();
});

test('a guest registering an existing account is saved, and the owner can cancel it', async ({ page }) => {
    // The same form and the same confirmation as anyone else.
    await visit(page, '/attend');
    await page.getByLabel('First name').fill('Someone');
    await page.getByLabel('Last name').fill('Else');
    await page.getByLabel('Email').fill(ACCOUNT_ONLY.email);
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByLabel('Organisation', { exact: true }).fill('Typed Organisation');
    await page.getByLabel('Job title').fill('Typed Title');
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByLabel(/Online/).check();
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText(/4\. What would you most like/)).toBeVisible();
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText('5. Networking')).toBeVisible();
    await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByRole('checkbox', { name: /I have read and accept the Terms/ }).check();
    await page.getByRole('button', { name: 'Complete registration' }).click();
    await expect(page).toHaveURL(/\/attend\/confirmation/);

    // The owner's email carries the cancel link.
    const link = lastCancelLink();
    expect(link, 'cancel link in the logged email').toBeTruthy();

    // An unsigned link is refused.
    const unsigned = await visit(page, link.split('?')[0]);
    expect(unsigned.status()).toBe(403);

    await visit(page, link);
    await expect(page.getByRole('heading', { level: 1, name: 'Cancel this registration' })).toBeVisible();
    await expect(page.getByText(/by someone who wasn't signed in to your account/)).toBeVisible();
    await expectNoViolations(page, 'registration not me, confirm');

    await page.getByRole('button', { name: 'Cancel this registration' }).click();
    // A POST and a redirect on the dev server: allow it more than the default wait.
    await expect(page).toHaveURL(/\/registration-cancelled$/, { timeout: 90_000 });
    await expect(page.getByRole('heading', { level: 1, name: 'That registration is cancelled' })).toBeVisible();
    await expectNoViolations(page, 'registration not me, cancelled');

    // The link has nothing left to cancel, and the account is back in the admin list.
    await visit(page, link);
    await expect(page.getByRole('heading', { level: 1, name: "There's nothing to cancel" })).toBeVisible();

    await login(page, ADMIN, /\/admin/);
    await visit(page, `/admin/events/${await eventId(page)}/attendees`);
    await expect(page.locator('section#accounts-only tr', { hasText: ACCOUNT_ONLY.email })).toBeVisible();
});
