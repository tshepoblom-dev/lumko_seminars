import { test, expect } from '@playwright/test';
import { ADMIN, SWITCHER, expectNoViolations, login, settle } from './support';

// "Offer online attendance" off: the event is in person only. Runs before
// zz-post-event, and turns the setting back on afterwards so later specs
// see the event as seeded. Set IN_PERSON_SHOTS=<dir> to save screenshots.

const SHOTS = process.env.IN_PERSON_SHOTS;
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }) : null);

// The single-threaded dev server sometimes resets a connection while the
// browser is fetching scripts, and the app never boots. Load the page again
// when that happens (up to four tries) instead of waiting out the test.
async function visit(page, url) {
    for (let attempt = 1; attempt <= 4; attempt++) {
        const response = await page.goto(url, { timeout: 60_000 }).catch(() => null);
        if (response && response.status() >= 400) return response;
        try {
            await page.locator('#app > *').first().waitFor({ state: 'attached', timeout: 20_000 });
            await settle(page);

            return response;
        } catch {
            // try again
        }
    }

    throw new Error(`${url} never rendered`);
}

async function setOnlineOffered(page, offered) {
    await login(page, ADMIN, /\/admin/);
    await visit(page, '/admin/events');
    const href = await page.locator('a[href*="/admin/events/"][href$="/edit"]').first().getAttribute('href');
    await visit(page, href);

    await page.getByLabel('Offer online attendance').setChecked(offered);
    await expect(page.getByLabel('Online price')).toHaveCount(offered ? 1 : 0);
    await page.getByRole('button', { name: 'Update event' }).click();
    await expect(page.getByText('Event updated.')).toBeVisible({ timeout: 60_000 });
}

test.describe.serial('in person only', () => {
    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await setOnlineOffered(page, true);
        await page.close();
    });

    test('staff turn online attendance off', async ({ page }) => {
        await setOnlineOffered(page, false);
    });

    test('the home page and registration offer in person only', async ({ page }) => {
        await visit(page, '/');
        const attend = page.locator('section#attend');
        await expect(attend.getByRole('heading', { name: 'Join us in person' })).toBeVisible();
        await expect(attend).not.toContainText('Online');
        await expect(attend.getByRole('heading', { name: 'In person', exact: true })).toBeVisible();
        await expect(attend).toContainText('The session is recorded, and the recording will be shared after the event.');
        // This event's own Format row (the series section below has its own).
        const format = page.locator('section[aria-labelledby="brief-title"] dt', { hasText: /^Format$/ }).locator('xpath=following-sibling::dd[1]');
        await expect(format).toHaveText('In person');
        await shot(page, 'home');
        await expectNoViolations(page, 'home, in person only');

        await visit(page, '/attend');
        await page.getByLabel('First name').fill('Only');
        await page.getByLabel('Last name').fill('InPerson');
        await page.getByLabel('Email').fill(`in-person-only-${Date.now()}@example.test`);
        await page.getByRole('button', { name: 'Next' }).click();
        await page.getByLabel('Organisation', { exact: true }).fill('Acme');
        await page.getByLabel('Job title').fill('Tester');
        await page.getByRole('button', { name: 'Next' }).click();

        await expect(page.getByText('3. How will you attend?')).toBeVisible();
        await expect(page.getByRole('radio', { name: /Online/ })).toHaveCount(0);
        await expect(page.getByRole('radio', { name: /In person/ })).toBeChecked();
        await expect(page.getByText('This event is in person only.')).toBeVisible();
        await shot(page, 'register-step-3');
        await expectNoViolations(page, 'registration step 3, in person only');
    });

    test('someone registered online is offered the switch and makes it', async ({ page }) => {
        await login(page, SWITCHER, /\/dashboard/);
        await settle(page);

        const notice = page.locator('section', { has: page.getByRole('heading', { name: 'This event is now in person only' }) });
        await expect(notice).toBeVisible();
        await expect(notice).toContainText("Can't make it? The session is recorded");
        await expect(page.getByText('Watching online')).toHaveCount(0);
        await shot(page, 'dashboard-notice');
        await expectNoViolations(page, 'dashboard, in person only notice');

        await notice.getByRole('button', { name: /Switch to in person \(R\d+\)/ }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog).toContainText('Your registration changes from online to in person');
        await expectNoViolations(page, 'dashboard, switch confirmation');
        await dialog.getByRole('button', { name: 'Switch to in person' }).click();

        await expect(page).toHaveURL(/\/payment$/, { timeout: 90_000 });
        await expect(page.getByText("You're now registered to attend in person.")).toBeVisible();
    });
});
