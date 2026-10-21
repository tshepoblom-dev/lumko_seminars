import { test, expect } from '@playwright/test';
import { ADMIN, INVITEE, expectNoViolations, firstHref, login, screenshot } from './support';

// Invitation codes (vouchers): seeded by `php artisan e2e:seed` —
//   E2E0-VCHR-0001  reserved for e2e-invitee@example.test (registered online)
//   E2E0-VCHR-0002  anyone may use it
// VOUCHER_SHOTS=<dir> saves a screenshot of each page for a visual check.
const SHOTS = process.env.VOUCHER_SHOTS;

async function stepOneAndTwo(page, email) {
    await page.getByLabel('First name').fill('Invited');
    await page.getByLabel('Last name').fill('Guest');
    await page.getByLabel('Email').fill(email);
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText('2. Organisation & role')).toBeVisible();
    await page.getByLabel('Organisation', { exact: true }).fill('Guest Co');
    await page.getByLabel('Job title').fill('Director');
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText('3. How will you attend?')).toBeVisible();
}

test('a guest following an invitation link registers with a complimentary ticket', async ({ page }) => {
    test.setTimeout(120_000);
    await page.goto('/attend?code=E2E0-VCHR-0002');
    await expect(page.getByText(/Your invitation code is filled in for you/)).toBeVisible();

    await stepOneAndTwo(page, 'invited.guest.e2e@example.test');

    // In person is already chosen and the code filled in.
    await expect(page.getByRole('radio', { name: /In person/ })).toBeChecked();
    await expect(page.getByLabel('Invitation code', { exact: true })).toHaveValue('E2E0-VCHR-0002');
    await expectNoViolations(page, 'Register step 3 with an invitation code');
    await screenshot(page, SHOTS, 'voucher-01-step3-prefilled');

    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page.getByText(/4\. What would you most like/)).toBeVisible();

    // Back on step 3 the accepted code shows as complimentary.
    await page.getByRole('button', { name: 'Back' }).click();
    await expect(page.getByRole('radio', { name: /In person.*Complimentary/ })).toBeVisible();
    await screenshot(page, SHOTS, 'voucher-02-step3-accepted');

    for (let i = 0; i < 3; i++) await page.getByRole('button', { name: 'Next' }).click();
    await page.getByRole('button', { name: 'Review' }).click();

    await expect(page.getByText('In person — Complimentary (invitation code)')).toBeVisible();
    await screenshot(page, SHOTS, 'voucher-03-review');
    await page.getByRole('checkbox', { name: /I have read and accept the Terms/ }).check();
    await page.getByRole('button', { name: 'Complete registration' }).click();

    await expect(page).toHaveURL(/\/attend\/confirmation/);
});

test('a mistyped code is reported on step 3 and the visitor stays there', async ({ page }) => {
    await page.goto('/attend');
    await stepOneAndTwo(page, 'typo.e2e@example.test');

    await page.getByRole('radio', { name: /In person/ }).check();
    await page.getByRole('button', { name: 'Have an invitation code?' }).click();
    const field = page.getByLabel('Invitation code', { exact: true });
    await expect(field).toBeFocused();
    await field.fill('ABCD-EFGH-JKMN');
    await page.getByRole('button', { name: 'Next' }).click();

    await expect(page.getByRole('alert')).toContainText("We don't recognise that invitation code");
    await expect(page.getByText('3. How will you attend?')).toBeVisible();
    await screenshot(page, SHOTS, 'voucher-04-step3-error');

    // Switching to Online drops the code entirely.
    await page.getByRole('radio', { name: /Online/ }).check();
    await expect(page.getByLabel('Invitation code', { exact: true })).toHaveCount(0);
});

test('an online attendee redeems their invitation from their account and gets a QR ticket', async ({ page }) => {
    await login(page, INVITEE);
    await page.goto('/redeem?code=E2E0-VCHR-0001');
    await expect(page.getByLabel('Invitation code', { exact: true })).toHaveValue('E2E0-VCHR-0001');
    await expectNoViolations(page, 'Redeem an invitation');
    await screenshot(page, SHOTS, 'voucher-05-redeem');

    await page.getByRole('button', { name: 'Redeem code' }).click();
    await page.waitForURL(/\/ticket$/);

    const qr = page.getByTestId('ticket-qr');
    await expect(qr.locator('svg')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('[object Object]');
    await screenshot(page, SHOTS, 'voucher-06-ticket');

    // The dashboard no longer asks for a payment.
    await page.goto('/dashboard');
    await expect(page.getByText('Complete your payment')).toHaveCount(0);
    await screenshot(page, SHOTS, 'voucher-07-dashboard');
});

test('an admin issues a code and sees it listed with the redeemed ones', async ({ page }) => {
    await login(page, ADMIN);
    const eventEdit = await firstHref(page, '/admin/events', /\/admin\/events\/\d+\/edit$/);
    const ws = `/admin/events/${eventEdit.match(/events\/(\d+)\//)[1]}`;

    await page.goto(`${ws}/vouchers`);
    await expect(page.getByRole('heading', { name: 'Vouchers' })).toBeVisible();
    // DataTable renders each row twice (table + phone list), so look in the table.
    const table = page.getByRole('table');
    await expect(table.getByText('E2E Unused', { exact: true })).toBeVisible();

    await page.getByLabel('Name').fill('Walk-in Dignitary');
    await page.getByRole('button', { name: 'Create code' }).click();

    await expect(page.getByText('Invitation code created for Walk-in Dignitary.')).toBeVisible();
    await expect(table.getByText('Walk-in Dignitary', { exact: true })).toBeVisible();
    await expectNoViolations(page, 'Vouchers after issuing a code');
    await screenshot(page, SHOTS, 'voucher-08-admin');

    // Revoke goes through a confirmation dialog.
    await page.getByRole('button', { name: 'Revoke the code for Walk-in Dignitary' }).click();
    const dialog = page.getByRole('dialog', { name: 'Revoke invitation code' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Revoke code' }).click();
    await expect(page.getByText('The code for Walk-in Dignitary no longer works.')).toBeVisible();
});
