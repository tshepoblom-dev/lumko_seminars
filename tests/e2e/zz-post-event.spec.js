import { execSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { ATTENDEE, expectNoViolations, login, scanAll } from './support';

// Named zz- so it runs last: it moves the seeded event into the past, which
// the other specs (a live, upcoming event) must not see.
test.describe.serial('after the event has ended', () => {
    test.beforeAll(() => {
        execSync('php artisan e2e:end-event --env=testing', {
            cwd: process.cwd(),
            env: { ...process.env, XDEBUG_MODE: 'off' },
            stdio: 'pipe',
        });
    });

    test('public post-event pages have no axe violations and show the recording', async ({ page }) => {
        await page.goto('/');
        await expect(page.getByText('Thank you to everyone who joined us')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Watch the recording' })).toBeVisible();

        expect(await scanAll(page, ['/', '/attend'])).toEqual([]);
        await expect(page.getByText(/has ended/)).toBeVisible();
    });

    test('attendee post-event pages have no axe violations', async ({ page }) => {
        await login(page, ATTENDEE);
        expect(await scanAll(page, ['/dashboard', '/live', '/resources', '/networking'])).toEqual([]);
    });

    test('the feedback form is open, accessible, and can be submitted', async ({ page }) => {
        await login(page, ATTENDEE);
        await page.goto('/feedback');
        await expect(page.getByRole('heading', { name: /How was/ })).toBeVisible();
        await expectNoViolations(page, 'Feedback (open)');

        // The rating buttons expose their selected state.
        await page.getByRole('button', { name: '4 out of 5' }).click();
        await expect(page.getByRole('button', { name: '4 out of 5' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByRole('button', { name: '3 out of 5' })).toHaveAttribute('aria-pressed', 'false');

        await page.getByLabel('What was your biggest takeaway?').fill('Start small, measure everything.');
        await page.getByRole('button', { name: 'Submit feedback' }).click();

        await expect(page).toHaveURL(/\/feedback\/thanks/);
        await expectNoViolations(page, 'Feedback thanks');
    });
});
