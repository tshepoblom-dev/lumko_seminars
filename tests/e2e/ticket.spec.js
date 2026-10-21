import { readFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';
import { IN_PERSON_PAID, IN_PERSON_PENDING, login, screenshot } from './support';

// TICKET_SHOTS=<dir> saves the ticket page and dashboard for a visual check.
const SHOTS = process.env.TICKET_SHOTS;

test('a confirmed ticket downloads as a PDF from the ticket page and the dashboard', async ({ page }) => {
    await login(page, IN_PERSON_PAID);
    await page.goto('/ticket');
    await screenshot(page, SHOTS, 'ticket-page');

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('link', { name: 'Download PDF' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/-ticket\.pdf$/);
    expect(readFileSync(await download.path()).subarray(0, 5).toString()).toBe('%PDF-');

    await page.goto('/dashboard');
    const block = page.getByRole('region', { name: 'Your ticket' });
    await expect(block).toBeVisible();
    await expect(block.getByRole('link', { name: 'Download PDF' })).toHaveAttribute('href', /\/ticket\/pdf$/);
    await screenshot(page, SHOTS, 'ticket-dashboard');
});

test('a ticket awaiting payment approval offers no PDF', async ({ page }) => {
    await login(page, IN_PERSON_PENDING);
    await page.goto('/ticket');
    await expect(page.getByRole('link', { name: 'Download PDF' })).toHaveCount(0);

    await page.goto('/dashboard');
    await expect(page.getByRole('region', { name: 'Your ticket' })).toHaveCount(0);
});

// The ticket page is the only place an attendee gets their check-in QR code.
// axe passes whether or not a QR is drawn, so look at what is actually rendered.
test('a confirmed ticket shows a real QR image and the code as text', async ({ page }) => {
    await login(page, IN_PERSON_PAID);
    await page.goto('/ticket');

    const qr = page.getByTestId('ticket-qr');
    await expect(qr.locator('svg')).toBeVisible();
    const box = await qr.locator('svg').boundingBox();
    expect(box.width).toBeGreaterThan(150);
    expect(box.height).toBeGreaterThan(150);

    // It is announced as one image, with the code as its text alternative.
    await expect(qr).toHaveAttribute('role', 'img');
    await expect(page.getByTestId('ticket-code')).toHaveText('E2ETEST1');

    // The original bug rendered the serialised object.
    await expect(page.locator('body')).not.toContainText('[object Object]');
});

test('a ticket awaiting payment approval shows no QR and no code', async ({ page }) => {
    await login(page, IN_PERSON_PENDING);
    await page.goto('/ticket');

    await expect(page.getByText('Your ticket will appear here once your payment has been approved.')).toBeVisible();
    await expect(page.getByTestId('ticket-qr')).toHaveCount(0);
    await expect(page.getByTestId('ticket-code')).toHaveCount(0);
});
