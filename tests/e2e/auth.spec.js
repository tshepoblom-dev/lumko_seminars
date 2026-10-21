import { test, expect } from '@playwright/test';

test('staff login redirects to the admin dashboard', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

    await page.getByLabel('Email').fill('admin@lumko.cloud');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/\/admin/);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
});

test('wrong password shows a validation error and does not log in', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email').fill('admin@lumko.cloud');
    await page.getByLabel('Password', { exact: true }).fill('wrong-password');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByText(/credentials do not match|these credentials/i)).toBeVisible();
});

test('attendee login redirects to their own dashboard, not admin', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email').fill('e2e-attendee@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Password123!');
    await page.getByRole('button', { name: 'Log in' }).click();

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByRole('heading', { name: 'Welcome' })).toBeVisible();
});
