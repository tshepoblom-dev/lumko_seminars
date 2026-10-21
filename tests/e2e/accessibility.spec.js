import { test, expect } from '@playwright/test';
import { ADMIN, ATTENDEE, expectNoViolations, login } from './support';

test('Home has no axe violations', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectNoViolations(page, 'Home');
});

test('Register (step 1) has no axe violations', async ({ page }) => {
    await page.goto('/attend');
    await expect(page.getByRole('heading', { name: /Register for/ })).toBeVisible();
    await expectNoViolations(page, 'Register');
});

test('Login has no axe violations', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
    await expectNoViolations(page, 'Login');
});

test('Attendee dashboard has no axe violations', async ({ page }) => {
    await login(page, ATTENDEE);
    await expect(page.getByRole('heading', { name: 'Welcome' })).toBeVisible();
    await expectNoViolations(page, 'Attendee dashboard');
});

test('Live page has no axe violations', async ({ page }) => {
    await login(page, ATTENDEE);
    await page.goto('/live');
    await expect(page.getByRole('heading', { name: 'The Power of Data' })).toBeVisible();
    await expectNoViolations(page, 'Live');
});

test('Admin dashboard has no axe violations', async ({ page }) => {
    await login(page, ADMIN);
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expectNoViolations(page, 'Admin dashboard');
});
