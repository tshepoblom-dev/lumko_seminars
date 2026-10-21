// TEMPORARY — screenshot pass for the event-workspace change; deleted after review.
import { test, expect } from '@playwright/test';
import { ADMIN, login } from './support.js';

const DIR = process.env.WS_SHOTS;

for (const vp of [{ name: 'desktop', width: 1366, height: 900 }, { name: 'phone', width: 390, height: 844 }]) {
    test(`workspace pages render (${vp.name})`, async ({ page }) => {
        test.setTimeout(240_000);
        await page.setViewportSize({ width: vp.width, height: vp.height });
        await login(page, ADMIN);

        await page.goto('/admin/events');
        const href = await page.getByRole('link', { name: 'Open' }).first().getAttribute('href');
        const ws = new URL(href, 'http://x').pathname;

        const pages = {
            overview: ws,
            programme: `${ws}/sessions`,
            'session-create': `${ws}/sessions/create`,
            speakers: `${ws}/speakers`,
            attendees: `${ws}/attendees`,
            payments: `${ws}/payments`,
            'check-in': `${ws}/check-in`,
            announcements: `${ws}/announcements`,
            broadcast: `${ws}/live/broadcast`,
            settings: `${ws}/edit`,
            'speaker-library': '/admin/speakers',
            'events-index': '/admin/events',
        };

        for (const [name, url] of Object.entries(pages)) {
            await page.goto(url);
            await page.waitForLoadState('networkidle');
            const text = await page.locator('main').innerText();
            expect(text, `${name} shows an object dump`).not.toContain('[object Object]');
            expect(text, `${name} shows undefined`).not.toMatch(/\bundefined\b|\bNaN\b/);
            await page.screenshot({ path: `${DIR}/${vp.name}-${name}.png`, fullPage: true });
        }

        // Edit a session: the multi-speaker checklist.
        await page.goto(`${ws}/sessions`);
        const edit = await page.locator('a', { hasText: 'Edit' }).first().getAttribute('href');
        await page.goto(edit);
        await page.screenshot({ path: `${DIR}/${vp.name}-session-edit.png`, fullPage: true });

        // Add-from-library dialog.
        await page.goto(`${ws}/speakers`);
        const add = page.getByRole('button', { name: 'Add from library' });
        if (await add.isEnabled()) {
            await add.click();
            await page.screenshot({ path: `${DIR}/${vp.name}-speakers-add-dialog.png`, fullPage: true });
        }

        // An attendee's page with the account card.
        await page.goto(`${ws}/attendees`);
        const view = await page.locator('a', { hasText: 'View' }).first().getAttribute('href');
        await page.goto(view);
        await page.screenshot({ path: `${DIR}/${vp.name}-attendee-show.png`, fullPage: true });

        // Public home: programme + speakers from the new links.
        await page.goto('/');
        await page.screenshot({ path: `${DIR}/${vp.name}-public-home.png`, fullPage: true });
    });
}
