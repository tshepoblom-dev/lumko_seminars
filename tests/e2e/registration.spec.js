import { test, expect } from '@playwright/test';
import { ATTENDEE, login } from './support';

test.describe('Registration stepper', () => {
    test('happy path through all 7 steps to confirmation', async ({ page }) => {
        await page.goto('/attend');
        await expect(page.getByRole('heading', { name: /Register for/ })).toBeVisible();

        // Step 1 — Your details
        await page.getByLabel('First name').fill('Jordan');
        await page.getByLabel('Last name').fill('Attendee');
        await page.getByLabel('Email').fill('jordan.e2e@example.test');
        await page.getByLabel('Mobile').fill('+27821234567');
        await page.getByRole('button', { name: 'Next' }).click();

        // Step 2 — Organisation & role
        await expect(page.getByText('2. Organisation & role')).toBeVisible();
        await page.getByLabel('Organisation', { exact: true }).fill('Acme Testing Co');
        await page.getByLabel('Job title').fill('QA Lead');
        await page.getByRole('button', { name: 'Next' }).click();

        // Step 3 — Attendance mode
        await expect(page.getByText('3. How will you attend?')).toBeVisible();
        await page.getByLabel(/Online/).check();
        await page.getByRole('button', { name: 'Next' }).click();

        // Step 4 — the event's questions, all optional by default (skip).
        // Improvement areas and the challenge now live here too.
        await expect(page.getByText(/4\. What would you most like/)).toBeVisible();
        await expect(page.getByRole('group', { name: 'Areas you would like to improve' })).toBeVisible();
        await page.getByRole('button', { name: 'Next' }).click();

        // Step 5 — Networking
        await expect(page.getByText('5. Networking')).toBeVisible();
        await page.getByRole('button', { name: 'Next' }).click();

        // Step 6 — Staying in touch
        await expect(page.getByText('6. Staying in touch')).toBeVisible();
        await page.getByRole('button', { name: 'Review' }).click();

        // Review panel
        await expect(page.getByText('Review your registration')).toBeVisible();
        await expect(page.getByText('Jordan Attendee')).toBeVisible();
        await expect(page.getByText('jordan.e2e@example.test')).toBeVisible();

        // The Terms & Privacy Policy must be accepted: without it, nothing is submitted.
        await page.getByRole('button', { name: 'Complete registration' }).click();
        await expect(page.getByText('Please accept the Terms & Conditions and the Privacy Policy to register.')).toBeVisible();
        await expect(page).toHaveURL(/\/attend$/);

        await page.getByRole('checkbox', { name: /I have read and accept the Terms/ }).check();
        await page.getByRole('button', { name: 'Complete registration' }).click();

        await expect(page).toHaveURL(/\/attend\/confirmation/);
        await expect(page.getByText(/confirmation to your email/i)).toBeVisible();
    });

    test('shows inline validation errors for an invalid first step', async ({ page }) => {
        await page.goto('/attend');

        await page.getByLabel('Email').fill('not-an-email');
        await page.getByRole('button', { name: 'Next' }).click();

        await expect(page.getByText('Please fix the following before continuing:')).toBeVisible();
        // Each message appears twice by design: once in the error summary
        // (which receives focus) and once inline next to its field.
        const summary = page.getByRole('alert');
        await expect(summary.getByText(/first name field is required/i)).toBeVisible();
        await expect(summary.getByText(/valid email address/i)).toBeVisible();
        await expect(page.locator('#public-register-1 + p')).toContainText(/first name field is required/i);

        // Still on step 1 — did not advance.
        await expect(page.getByText('1. Your details')).toBeVisible();
    });

    test('a reload restores the draft fields but never the free text or consent boxes', async ({ page }) => {
        await page.goto('/attend');

        await page.getByLabel('First name').fill('Draft');
        await page.getByLabel('Last name').fill('Person');
        await page.getByLabel('Email').fill('draft.e2e@example.test');
        await page.getByLabel('Mobile').fill('+27821234567');
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByLabel('Organisation', { exact: true }).fill('Draft Co');
        await page.getByLabel('Job title').fill('Tester');
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByRole('button', { name: 'Next' }).click(); // step 3 -> 4
        await page.getByRole('checkbox', { name: 'Data governance' }).check(); // a choice: kept in the draft
        await page.getByLabel('A technology challenge your organisation is facing').fill('Secret free text');
        await page.getByRole('button', { name: 'Next' }).click(); // step 4 -> networking
        await page.getByLabel(/Make my profile/).check();

        await page.reload();

        // Draft fields come back...
        await expect(page.getByLabel('First name')).toHaveValue('Draft');
        await expect(page.getByLabel('Email')).toHaveValue('draft.e2e@example.test');

        // ...choices too, but the free-text field and consent checkbox must not.
        for (let i = 0; i < 3; i++) await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.getByRole('checkbox', { name: 'Data governance' })).toBeChecked();
        await expect(page.getByLabel('A technology challenge your organisation is facing')).toHaveValue('');
        await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.getByText('5. Networking')).toBeVisible();
        await expect(page.getByLabel(/Make my profile/)).not.toBeChecked();
    });

    async function fillStepOne(page) {
        await page.getByLabel('First name').fill('Retry');
        await page.getByLabel('Last name').fill('Person');
        await page.getByLabel('Email').fill('retry.e2e@example.test');
        await page.getByLabel('Mobile').fill('+27821234567');
    }

    test('a rate-limited check is explained, focused, and recoverable — never silent', async ({ page }) => {
        await page.goto('/attend');
        await fillStepOne(page);

        await page.route('**/attend/validate-step', (route) => route.fulfill({
            status: 429, contentType: 'application/json', body: JSON.stringify({ message: 'Too Many Attempts.' }),
        }));
        await page.getByRole('button', { name: 'Next' }).click();

        const alert = page.getByRole('alert');
        await expect(alert).toContainText('Too many requests');
        await expect(alert).toBeFocused();
        await expect(page.getByText('1. Your details')).toBeVisible();

        // Once the limit clears the same button works and the message goes away.
        await page.unroute('**/attend/validate-step');
        await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.getByText('2. Organisation & role')).toBeVisible();
        await expect(page.getByRole('alert')).toHaveCount(0);
    });

    test('a dropped connection during the check is explained and recoverable', async ({ page }) => {
        await page.goto('/attend');
        await fillStepOne(page);

        await page.route('**/attend/validate-step', (route) => route.abort('failed'));
        await page.getByRole('button', { name: 'Next' }).click();

        await expect(page.getByRole('alert')).toContainText("couldn't reach the server");
        await expect(page.getByText('1. Your details')).toBeVisible();

        await page.unroute('**/attend/validate-step');
        await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.getByText('2. Organisation & role')).toBeVisible();
    });

    // Regression: signed in, the whole of step 1 was disabled — including a
    // mobile the account didn't have — so "Next" could never pass.
    test('signed in, name and email are locked but mobile can be typed or left blank', async ({ page }) => {
        await login(page, ATTENDEE);
        await page.goto('/attend');

        await expect(page.getByLabel('First name')).toBeDisabled();
        await expect(page.getByLabel('Email')).toBeDisabled();
        await expect(page.getByLabel('Mobile')).toBeEnabled();

        await page.getByLabel('Mobile').fill('+27821234567');
        await expect(page.getByLabel('Mobile')).toHaveValue('+27821234567');
        await page.getByLabel('Mobile').fill('');

        // Optional: a blank mobile still moves on to step 2. Nothing is submitted.
        await page.getByRole('button', { name: 'Next' }).click();
        await expect(page.getByText('2. Organisation & role')).toBeVisible();
    });
});
