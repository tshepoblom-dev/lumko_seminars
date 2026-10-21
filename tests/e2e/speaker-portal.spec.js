import { test, expect } from '@playwright/test';
import { ADMIN, ATTENDEE, SPEAKER, expectNoViolations, login, settle } from './support';

// The speaker portal: schedule, profile, resources, green room, stage and
// report, for the account e2e:seed links to the event's first speaker.
// There are no Agora credentials here, so joining the green room is
// expected to fail with "Agora is not configured"; the device check and
// page states are what's exercised.

const SHOTS = process.env.PORTAL_SHOTS_DIR;
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }) : null);

test('a speaker signs in to their portal and sees their schedule', async ({ page }) => {
    await login(page, SPEAKER, /\/speaker$/);
    await settle(page);

    await expect(page.getByRole('heading', { level: 1 })).toContainText('Welcome,');
    await expect(page.getByRole('heading', { level: 2, name: 'The Power of Data' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Test your setup' })).toBeVisible();
    await shot(page, 'speaker-dashboard');
    await expectNoViolations(page, 'speaker dashboard');

    await page.getByRole('link', { name: 'Edit your profile' }).click();
    await settle(page);
    await expect(page.getByLabel('Name')).not.toHaveValue('');
    await shot(page, 'speaker-profile');
    await expectNoViolations(page, 'speaker profile');
});

test('a speaker shares a link with attendees', async ({ page, browser }) => {
    await login(page, SPEAKER, /\/speaker$/);
    await page.getByRole('link', { name: 'Manage resources' }).first().click();
    await settle(page);

    await page.locator('#resource-title').fill('E2E speaker paper');
    await page.getByLabel('A link').check();
    await page.locator('#resource-url').fill('https://example.com/paper');
    await page.getByRole('button', { name: 'Share with attendees' }).click();
    await expect(page.getByText('Shared. Attendees can open it now')).toBeVisible();
    await expect(page.getByText('E2E speaker paper')).toBeVisible();
    await shot(page, 'speaker-resources');
    await expectNoViolations(page, 'speaker resources');

    const attendeeContext = await browser.newContext();
    const attendee = await attendeeContext.newPage();
    await login(attendee, ATTENDEE, /\/dashboard/);
    await attendee.goto('/resources');
    await settle(attendee);
    const item = attendee.locator('li', { hasText: 'E2E speaker paper' });
    await expect(item).toContainText('Shared by');
    await attendeeContext.close();
});

test('the green room checks devices and the stage and report open from the portal', async ({ page }) => {
    await login(page, SPEAKER, /\/speaker$/);
    await page.getByRole('link', { name: 'Test your setup' }).click();
    await settle(page);

    await expect(page.getByRole('heading', { level: 1, name: 'Test your setup' })).toBeVisible();
    await expect(page.getByText("Attendees can't see or hear it.")).toBeVisible();
    await page.getByRole('button', { name: 'Check camera and mic' }).click();
    await expect(page.getByRole('button', { name: 'Mute mic' })).toBeVisible();
    await shot(page, 'speaker-green-room');
    await expectNoViolations(page, 'speaker green room');

    // No Agora credentials in the e2e environment: joining fails visibly.
    await page.getByRole('button', { name: 'Join the green room' }).click();
    await expect(page.getByRole('alert')).toBeVisible();

    await page.goto('/speaker');
    await page.getByRole('link', { name: 'Open your stage' }).click();
    await settle(page);
    await expect(page.getByRole('link', { name: '← Back to your speaker portal' })).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Welcome,');
    await shot(page, 'speaker-stage');

    await page.goto('/speaker');
    await page.getByRole('link', { name: 'Open your report' }).click();
    await settle(page);
    await expect(page.getByRole('heading', { name: /People who asked to connect/ })).toBeVisible();
    await shot(page, 'speaker-report');
    await expectNoViolations(page, 'speaker report in the portal');
});

test('staff invite a speaker to the portal from the event Speakers page', async ({ page }) => {
    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/events');
    await settle(page);
    const href = await page.locator('a[href*="/admin/events/"][href$="/edit"]').first().getAttribute('href');
    const eventId = href.match(/events\/(\d+)\//)[1];

    await page.goto(`/admin/events/${eventId}/speakers`);
    await settle(page);

    // The seeded e2e speaker has an account; the other speaker has no contact email yet.
    const linked = page.locator('li', { hasText: 'Kgotla Paledi' });
    await expect(linked.getByText('Has a speaker account')).toBeVisible();
    const other = page.locator('li', { hasText: 'Combrink' });
    await expect(other.getByText('No speaker account yet')).toBeVisible();
    await shot(page, 'admin-event-speakers-before');

    // Add a contact email, then invite.
    await other.getByRole('link', { name: 'Add a contact email to invite them' }).click();
    await settle(page);
    await expect(page.getByRole('heading', { name: 'Speaker portal' })).toBeVisible();
    await page.getByLabel('Contact email').fill('combrink.e2e@example.test');
    await page.getByRole('button', { name: 'Update speaker' }).click();
    await settle(page);

    await page.goto(`/admin/events/${eventId}/speakers`);
    await settle(page);
    await page.locator('li', { hasText: 'Combrink' }).getByRole('button', { name: 'Invite to the speaker portal' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('combrink.e2e@example.test');
    await expect(dialog).toContainText('The link works once, for 14 days.');
    await shot(page, 'admin-invite-modal');
    await expectNoViolations(page, 'invite speaker modal');
    await dialog.getByRole('button', { name: 'Send invitation' }).click();

    await expect(page.getByText(/Portal invitation emailed to Dr Herkulaas M\. Combrink/)).toBeVisible();
    await expect(page.locator('li', { hasText: 'Combrink' }).getByText(/^Invited /)).toBeVisible();
    await expect(page.locator('li', { hasText: 'Combrink' }).getByRole('button', { name: 'Resend invitation' })).toBeVisible();
    await shot(page, 'admin-event-speakers-after');
    await expectNoViolations(page, 'event speakers with portal states');
});
