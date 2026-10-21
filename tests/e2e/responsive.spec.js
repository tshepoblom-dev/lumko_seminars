import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {
    ADMIN, ATTENDEE, IN_PERSON_PAID, adminUrls, axeProblems, layoutProblems, login, scanAll, screenshot, settle,
} from './support';

// Phone and tablet widths. The rest of the suite runs at desktop size, so
// anything that only breaks when the screen is narrow is invisible to it.
// Set RESPONSIVE_SHOTS=<dir> to also write full-page screenshots there.
const SHOTS = process.env.RESPONSIVE_SHOTS;

const VIEWPORTS = [
    { name: 'phone', width: 360, height: 740 },
    { name: 'tablet', width: 768, height: 1024 },
];

async function expectInViewport(page, locator, what) {
    const box = await locator.boundingBox();
    expect(box, `${what} should be rendered`).not.toBeNull();
    expect(box.x, `${what}: left edge`).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width, `${what}: right edge`).toBeLessThanOrEqual(page.viewportSize().width + 0.5);
}

for (const vp of VIEWPORTS) {
    test.describe(`${vp.name} (${vp.width}px)`, () => {
        test.use({ viewport: { width: vp.width, height: vp.height }, isMobile: true, hasTouch: true });

        const shots = (match) => (SHOTS ? { dir: SHOTS, prefix: vp.name, match } : null);

        test('guest pages fit the screen', async ({ page }) => {
            test.setTimeout(120_000);
            const problems = await scanAll(
                page,
                ['/', '/attend', '/login', '/forgot-password', '/attend/confirmation'],
                { layout: true, shots: shots(/^\/(attend)?$/) },
            );
            problems.push(...await scanAll(page, ['/no-such-page'], { allowStatus: [404], layout: true }));

            expect(problems).toEqual([]);
        });

        test('every step of the registration stepper fits the screen', async ({ page }) => {
            test.setTimeout(120_000);
            const problems = [];
            const check = async (label, shotName) => {
                await settle(page);
                for (const p of await axeProblems(page)) problems.push(`${label}: ${p}`);
                for (const p of await layoutProblems(page)) problems.push(`${label}: ${p}`);
                if (shotName) await screenshot(page, SHOTS, `${vp.name}-register-${shotName}`);
            };

            await page.goto('/attend');
            await page.getByLabel('First name').fill('Scan');
            await page.getByLabel('Last name').fill('Person');
            await page.getByLabel('Email').fill('responsive.person@example.test');
            await page.getByLabel('Mobile').fill('+27821234567');

            // The primary button must be reachable without sideways scrolling.
            await expectInViewport(page, page.getByRole('button', { name: 'Next' }), 'Register: Next button');

            for (let step = 1; step <= 7; step++) {
                if (step === 2) {
                    await page.getByLabel('Organisation', { exact: true }).fill('Scan Co');
                    await page.getByLabel('Job title').fill('Tester');
                }
                await check(`Register step ${step}`, [1, 4].includes(step) ? `step${step}` : null);
                await page.getByRole('button', { name: step === 7 ? 'Review' : 'Next' }).click();
            }

            await expect(page.getByText('Review your registration')).toBeVisible();
            await check('Register review panel', 'review');

            expect(problems).toEqual([]);
        });

        test('attendee pages fit the screen', async ({ page }) => {
            test.setTimeout(180_000);
            await login(page, ATTENDEE);

            expect(
                await scanAll(page, ['/dashboard', '/account', '/profile', '/networking', '/resources', '/feedback', '/live'], {
                    layout: true,
                    shots: shots(/^\/(dashboard|live)$/),
                }),
            ).toEqual([]);
        });

        test('in-person attendee pages (payment, QR ticket) fit the screen', async ({ page }) => {
            test.setTimeout(120_000);
            await login(page, IN_PERSON_PAID);

            expect(
                await scanAll(page, ['/dashboard', '/payment', '/ticket'], { layout: true, shots: shots(/ticket/) }),
            ).toEqual([]);
        });

        test('every admin page fits the screen', async ({ page }) => {
            test.setTimeout(400_000);
            await login(page, ADMIN);

            expect(
                await scanAll(page, await adminUrls(page), {
                    layout: true,
                    shots: shots(/(payments|attendees|control-room|check-in|events|speakers)$/),
                }),
            ).toEqual([]);
        });

        test('the admin menu is reachable and works', async ({ page }) => {
            await login(page, ADMIN);
            await page.goto('/admin');

            const menu = page.getByRole('button', { name: 'Menu' });
            const nav = page.getByRole('navigation', { name: 'Admin' });

            // The top nav is only Dashboard · Events · Speakers · Audit, so
            // from the sm breakpoint (640px) it sits inline with no Menu button.
            if (vp.width >= 640) {
                await expect(menu).toBeHidden();
                await expect(nav).toBeVisible();
                await nav.getByRole('link', { name: 'Events' }).click();
                await expect(page).toHaveURL(/\/admin\/events$/);
                return;
            }

            await expect(menu).toBeVisible();
            await expectInViewport(page, menu, 'Admin: Menu button');
            await expect(nav).toBeHidden();

            await menu.click();
            await expect(nav).toBeVisible();
            await expect(menu).toHaveAttribute('aria-expanded', 'true');
            await screenshot(page, SHOTS, `${vp.name}-admin-menu-open`);

            await nav.getByRole('link', { name: 'Events' }).click();
            await expect(page).toHaveURL(/\/admin\/events$/);
        });

        test('the delete-speaker dialog fits the screen', async ({ page }) => {
            await login(page, ADMIN);
            await page.goto('/admin/speakers');

            // DataTable renders a table (sm+) and a card list (phone); getByRole
            // skips the hidden one, so .first() is the one on screen.
            await page.getByRole('button', { name: 'Delete' }).first().click();
            const dialog = page.getByRole('dialog', { name: 'Delete speaker' });
            await expect(dialog).toBeVisible();
            await expectInViewport(page, dialog, 'Delete dialog');
            await expectInViewport(page, dialog.getByRole('button', { name: 'Cancel' }), 'Delete dialog: Cancel');
            expect(await layoutProblems(page)).toEqual([]);
            await screenshot(page, SHOTS, `${vp.name}-admin-modal`);
        });

        test('check-in: the manual entry and the submit button fit the screen', async ({ page }) => {
            await login(page, ADMIN);
            await page.goto('/admin/check-in');

            await expectInViewport(page, page.getByLabel('Manual entry'), 'Check-in: manual entry');
            await expectInViewport(page, page.getByRole('button', { name: 'Check in', exact: true }), 'Check-in: submit');
            await expectInViewport(page, page.getByRole('button', { name: 'Start camera scan' }), 'Check-in: camera button');
        });
    });
}

// Touch targets (WCAG 2.2 AA 2.5.8, 24×24 CSS px minimum). Not part of the
// 2.1 rule set used everywhere else, so it is reported separately: the list is
// printed and attached rather than failing the run until the owner decides to
// enforce it.
test.describe('touch target size (phone, report only)', () => {
    test.use({ viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true });

    test('list interactive elements smaller than 24x24 px', async ({ page }) => {
        test.setTimeout(240_000);

        // Prove the detector works before trusting an empty report: two 10 px
        // buttons side by side must be flagged.
        await page.setContent(
            '<!doctype html><html lang="en"><title>t</title><body><main>'
            + '<button aria-label="a" style="position:absolute;left:0;top:0;width:10px;height:10px;padding:0"></button>'
            + '<button aria-label="b" style="position:absolute;left:12px;top:0;width:10px;height:10px;padding:0"></button>'
            + '</main></body></html>',
        );
        const known = await new AxeBuilder({ page }).withRules(['target-size']).analyze();
        expect(known.violations.length, 'axe target-size flags a known 10x10 px target').toBeGreaterThan(0);

        const report = [];
        const scanTargets = async (urls) => {
            for (const url of urls) {
                await page.goto(url);
                await settle(page);
                const results = await new AxeBuilder({ page }).withRules(['target-size']).analyze();
                for (const v of results.violations) {
                    for (const node of v.nodes) {
                        report.push(`${url}: ${node.target} — ${node.failureSummary?.split('\n')[1]?.trim() ?? v.help}`);
                    }
                }
            }
        };

        await scanTargets(['/', '/attend', '/login']);
        await login(page, ATTENDEE);
        await scanTargets(['/dashboard', '/live', '/resources', '/account']);

        console.log(`touch-target report (${report.length}):\n${report.join('\n')}`);
        await test.info().attach('touch-targets.txt', { body: report.join('\n') || 'none', contentType: 'text/plain' });
    });
});
