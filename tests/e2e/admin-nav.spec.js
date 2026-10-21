import { test, expect } from '@playwright/test';

async function loginAsAdmin(page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill('admin@lumko.cloud');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/admin/);
}

test('top nav holds the cross-event areas; each event has its own workspace sidebar', async ({ page }) => {
    await loginAsAdmin(page);

    const nav = page.getByRole('navigation', { name: 'Admin' });
    for (const name of ['Dashboard', 'Events', 'Speakers', 'Audit']) {
        await expect(nav.getByRole('link', { name })).toBeVisible();
    }
    // Event-owned areas are no longer top-level.
    await expect(nav.getByRole('link', { name: 'Payments' })).toHaveCount(0);

    await nav.getByRole('link', { name: 'Events' }).click();
    await page.getByRole('link', { name: 'The Power of Data' }).first().click();
    await expect(page).toHaveURL(/\/admin\/events\/\d+$/);

    const eventNav = page.getByRole('navigation', { name: 'Event' });
    for (const name of ['Overview', 'Programme', 'Speakers', 'Attendees', 'Payments', 'Check-in', 'Broadcast', 'Settings']) {
        await expect(eventNav.getByRole('link', { name })).toBeVisible();
    }

    await eventNav.getByRole('link', { name: 'Attendees' }).click();
    await expect(page).toHaveURL(/\/admin\/events\/\d+\/attendees/);
    await expect(page.getByRole('heading', { name: /Attendees/ })).toBeVisible();
    await expect(eventNav.getByRole('link', { name: 'Attendees' })).toHaveAttribute('aria-current', 'page');
});

test('a slow page visit shows a decorative progress bar with no invalid ARIA role', async ({ page }) => {
    await loginAsAdmin(page);

    // Hold the Inertia request back so the bar has time to appear.
    await page.route('**/admin/events', async (route) => {
        if (route.request().headers()['x-inertia']) {
            await new Promise((resolve) => setTimeout(resolve, 1500));
        }
        await route.continue();
    });

    await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Events' }).click();

    // Not vacuous: the bar really is on screen, and hidden from assistive tech.
    const bar = page.locator('[data-navigation-progress]');
    await expect(bar).toBeVisible();
    await expect(page.locator('[aria-hidden="true"]:has([data-navigation-progress])')).toHaveCount(1);
    // Inertia's built-in bar (role="bar" / role="spinner") must be gone. This is
    // a one-shot read on purpose: expect(locator).toHaveCount(0) retries until
    // it's true, so it would pass once the visit ends and the bar is removed.
    const builtIn = await page.evaluate(
        () => document.querySelectorAll('#nprogress, [role="bar"], [role="spinner"]').length,
    );
    expect(builtIn, 'Inertia built-in progress elements while a visit is in flight').toBe(0);

    await expect(page).toHaveURL(/\/admin\/events$/);
    await expect(bar).toBeHidden();
});

test('attendee cannot reach the admin area', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('e2e-attendee@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Password123!');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard/);

    const response = await page.goto('/admin');
    expect(response.status()).toBe(403);
});
