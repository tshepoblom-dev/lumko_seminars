import { test, expect } from '@playwright/test';

async function loginAsAdmin(page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill('admin@lumko.cloud');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/admin/);
}

// A result must reach a screen reader once. The visible banner and the shared
// flash region both carry the text, so exactly one of them may be a live region.
function announcements(page, text) {
    return page.locator('[role="status"], [role="alert"]').filter({ hasText: text });
}

test.describe.serial('check-in manual entry fallback', () => {
    test('an unknown code is rejected, and announced once', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/check-in');

        await page.getByLabel('Manual entry').fill('NOPE-NOT-A-CODE');
        await page.getByRole('button', { name: 'Check in', exact: true }).click();

        await expect(page.getByText('No ticket found for that code.').first()).toBeVisible();
        await expect(announcements(page, 'No ticket found for that code.')).toHaveCount(1);
        await expect(page.getByLabel('Manual entry')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByLabel('Manual entry')).toHaveAccessibleDescription('No ticket found for that code.');
    });

    test('a valid code checks the attendee in, and a second scan reports already checked in', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/check-in');

        await page.getByLabel('Manual entry').fill('E2ETEST1');
        await page.getByRole('button', { name: 'Check in', exact: true }).click();
        await expect(page.getByText('Checked in: E2E CheckIn').first()).toBeVisible();
        await expect(announcements(page, 'Checked in: E2E CheckIn')).toHaveCount(1);

        await page.getByLabel('Manual entry').fill('E2ETEST1');
        await page.getByRole('button', { name: 'Check in', exact: true }).click();
        await expect(page.getByText(/Already checked in at/).first()).toBeVisible();
        await expect(announcements(page, /Already checked in at/)).toHaveCount(1);
    });
});
