import { test, expect } from '@playwright/test';
import {
    ATTENDEE, IN_PERSON_PAID, IN_PERSON_PENDING, UNVERIFIED,
    expectNoViolations, login, scanAll,
} from './support';

test.describe('guest pages', () => {
    test('auth, confirmation and error pages have no axe violations', async ({ page }) => {
        const problems = await scanAll(page, [
            '/forgot-password',
            '/reset-password/not-a-real-token?email=someone@example.test',
            '/confirm-registration/not-a-real-token?email=someone@example.test',
            '/attend/confirmation',
        ]);

        // The branded error page (APP_DEBUG=false in .env.testing).
        problems.push(...await scanAll(page, ['/no-such-page'], { allowStatus: [404] }));

        expect(problems).toEqual([]);
    });

    test('every step of the registration stepper and the review panel', async ({ page }) => {
        await page.goto('/attend');
        await page.getByLabel('First name').fill('Scan');
        await page.getByLabel('Last name').fill('Person');
        await page.getByLabel('Email').fill('scan.person@example.test');
        await page.getByLabel('Mobile').fill('+27821234567');

        // Six steps (the old step 5 was merged into step 4).
        for (let step = 1; step <= 6; step++) {
            if (step === 2) {
                await page.getByLabel('Organisation', { exact: true }).fill('Scan Co');
                await page.getByLabel('Job title').fill('Tester');
            }
            await expectNoViolations(page, `Register step ${step}`);
            await page.getByRole('button', { name: step === 6 ? 'Review' : 'Next' }).click();
        }

        await expect(page.getByText('Review your registration')).toBeVisible();
        await expectNoViolations(page, 'Register review panel');
    });
});

test.describe('signed-in pages', () => {
    test('online attendee: dashboard, account, networking, resources, feedback (not yet open)', async ({ page }) => {
        // Seven pages on single-threaded `artisan serve` need more than the default 45s.
        test.setTimeout(120_000);
        await login(page, ATTENDEE);
        expect(await scanAll(page, ['/dashboard', '/account', '/profile', '/networking', '/resources', '/feedback', '/live'])).toEqual([]);
    });

    test('in-person attendee with a pending payment: payment upload, ticket, dashboard, invitation code', async ({ page }) => {
        await login(page, IN_PERSON_PENDING);
        expect(await scanAll(page, ['/dashboard', '/payment', '/ticket', '/redeem'])).toEqual([]);
    });

    // axe only: whether the QR is actually drawn is asserted in ticket.spec.js.
    test('in-person attendee with an approved payment: payment, ticket', async ({ page }) => {
        await login(page, IN_PERSON_PAID);
        expect(await scanAll(page, ['/dashboard', '/payment', '/ticket'])).toEqual([]);
    });

    test('a signed-in user with an unverified email sees the verify-email page', async ({ page }) => {
        await login(page, UNVERIFIED, /\/verify-email/);
        await expectNoViolations(page, 'Verify email');
    });

    test('an attendee opening the admin area gets the branded 403 page', async ({ page }) => {
        await login(page, ATTENDEE);
        expect(await scanAll(page, ['/admin'], { allowStatus: [403] })).toEqual([]);
    });
});
