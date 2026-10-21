import { test, expect } from '@playwright/test';

test('live page degrades to the polling fallback when Pusher is not configured', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('e2e-attendee@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Password123!');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard/);

    await page.goto('/live');

    await expect(page.getByRole('heading', { name: 'The Power of Data' })).toBeVisible();
    await expect(page.getByText('Not live yet')).toBeVisible();

    // No Pusher credentials in .env.testing, so the realtime composable
    // must fall back to polling and say so via an aria-live status.
    await expect(page.getByRole('status').filter({ hasText: 'Live updates paused' })).toBeVisible();

    // The Q&A form is still usable without realtime.
    await expect(page.getByPlaceholder('Ask a question...')).toBeVisible();
});

test('a submitted question is acknowledged as awaiting moderation', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('e2e-attendee@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Password123!');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard/);

    await page.goto('/live');
    await page.getByPlaceholder('Ask a question...').fill('How do we start with data governance?');
    await page.getByRole('button', { name: 'Submit', exact: true }).click();

    await expect(page.getByText('Question submitted for moderation.')).toBeVisible();
    await expect(page.getByText('How do we start with data governance?')).toBeVisible();
});
