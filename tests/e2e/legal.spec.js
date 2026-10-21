import { test, expect } from '@playwright/test';
import { NO_TERMS, expectNoViolations, login, settle } from './support';

// Privacy Policy and Terms & Conditions: public, accessible, linked from the
// footer; existing accounts accept them once before using the portal.

const SHOTS = process.env.LIVESTREAM_SHOTS_DIR;
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }) : null);

for (const [path, title] of [['/privacy', 'Privacy Policy'], ['/terms', 'Terms & Conditions']]) {
    test(`${title} is public, readable and marked as a draft until completed`, async ({ page }) => {
        await page.goto(path);
        await settle(page);

        await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
        await expect(page.getByRole('note')).toContainText('Draft pending legal review');
        // Missing organiser facts are visible, not silently blank.
        await expect(page.locator('.todo').first()).toHaveText('[TO COMPLETE]');
        // Every contents entry jumps to a section that exists.
        const hrefs = await page.getByRole('navigation', { name: 'Contents' }).getByRole('link').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
        expect(hrefs.length).toBeGreaterThan(10);
        for (const href of hrefs) await expect(page.locator(href)).toHaveCount(1);

        await expectNoViolations(page, title);
        await shot(page, `legal${path.replace('/', '-')}`);
    });
}

test('the footer links both documents', async ({ page }) => {
    await page.goto('/');
    await settle(page);
    const footer = page.locator('footer');
    await expect(footer.getByRole('link', { name: 'Privacy Policy' })).toHaveAttribute('href', /\/privacy$/);
    await expect(footer.getByRole('link', { name: 'Terms & Conditions' })).toHaveAttribute('href', /\/terms$/);
});

test('an existing account accepts once, then carries on to the portal', async ({ page }) => {
    await login(page, NO_TERMS, /\/legal\/accept/);
    await settle(page);

    await expect(page.getByRole('heading', { name: 'Before you continue' })).toBeVisible();
    await expectNoViolations(page, 'legal accept');
    await shot(page, 'legal-accept');

    // Can't skip it by going elsewhere in the portal.
    await page.goto('/live');
    await expect(page).toHaveURL(/\/legal\/accept/);

    await page.getByRole('button', { name: 'Accept and continue' }).click();
    await expect(page.getByText('Please tick the box to accept')).toBeVisible();

    await page.getByRole('checkbox', { name: /I have read and accept/ }).check();
    await page.getByRole('button', { name: 'Accept and continue' }).click();

    // Back to where they were heading.
    await expect(page).toHaveURL(/\/live$/);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/dashboard$/);
});

// While an event is open there is no account-only sign-up: the event form
// creates the account (and records acceptance of the terms on its last step).
test('sign-up leads to the event registration form while an event is open', async ({ page }) => {
    await page.goto('/register');
    await settle(page);
    await expect(page).toHaveURL(/\/attend$/);
    await expect(page.getByLabel('First name')).toBeVisible();

    await page.goto('/login');
    await settle(page);
    await expect(page.getByRole('link', { name: 'Create one' })).toHaveCount(0);
    await page.getByRole('link', { name: 'Register for the event' }).click();
    await expect(page).toHaveURL(/\/attend$/);
});
