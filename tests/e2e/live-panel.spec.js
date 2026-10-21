import { test, expect } from '@playwright/test';
import { ADMIN, ATTENDEE, expectNoViolations, login, settle } from './support';

// The live page as a stream with chat & Q&A beside it, polls over it and
// emoji reactions floating up it — plus the presenter tools that drive
// them. The test server logs broadcasts instead of sending them (there's
// no Pusher here), so where another viewer would get a push, these tests
// reload; what's checked is each side's own behaviour and the real-time
// authorisation request itself.

const SHOTS = process.env.LIVESTREAM_SHOTS_DIR;
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png` }) : null);

const pageProps = (page) => page.evaluate(() => JSON.parse(document.getElementById('app').dataset.page).props);

// The admin workspace base for the e2e event, e.g. /admin/events/3/live.
async function workspaceLive(page) {
    await page.goto('/admin/live/control-room');
    await settle(page);
    const href = await page.getByRole('link', { name: 'Open broadcast console' }).getAttribute('href');
    return new URL(href, page.url()).pathname.replace(/\/broadcast$/, '');
}

test('an attendee\'s real-time channel authorisation request goes through', async ({ page }) => {
    await login(page, ATTENDEE, /\/dashboard/);
    await page.goto('/live');
    await settle(page);
    const { event } = await pageProps(page);

    // Exactly what echo.js's authorizer sends. (The test server's `log`
    // broadcaster signs nothing, so this checks the request is accepted —
    // session, CSRF, verified — not the Pusher signature.)
    const status = await page.evaluate(async (channel) => {
        const res = await window.axios.post('/broadcasting/auth', { socket_id: '123.456', channel_name: channel }, { headers: { Accept: 'application/json' } })
            .catch((e) => e.response);
        return res.status;
    }, `private-event.${event.id}`);
    expect(status).toBe(200);
});

test('attendees chat, ask and react beside the stream; the panel collapses and remembers', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await login(page, ATTENDEE, /\/dashboard/);
    await page.goto('/live');
    await settle(page);

    const panel = page.locator('[data-side-panel]');
    await expect(panel).toBeVisible();
    // The panel sits over the right of the stage, and the stream steps left of it.
    const stage = await page.locator('[data-player]').boundingBox();
    const panelBox = await panel.boundingBox();
    const content = await page.locator('[data-stage-content]').boundingBox();
    expect(Math.round(panelBox.x + panelBox.width)).toBe(Math.round(stage.x + stage.width));
    expect(Math.round(content.x + content.width)).toBeLessThanOrEqual(Math.round(panelBox.x) + 1);

    // Chat: posts show at once.
    const message = `Hello from the e2e suite ${Date.now()}`;
    await panel.getByRole('textbox', { name: 'Chat message' }).fill(message);
    await panel.getByRole('button', { name: 'Send' }).click();
    await expect(panel.getByRole('list', { name: 'Chat messages' })).toContainText(message);
    await expect(panel.getByRole('list', { name: 'Chat messages' })).toContainText('E2E A.');

    // Q&A: the approved question shows, and a new one waits for moderation.
    await panel.getByRole('tab', { name: 'Q&A' }).click();
    await expect(panel).toContainText('E2E approved question?');
    const question = `What about data residency? ${Date.now()}`;
    await panel.getByLabel('Your question for the speakers').fill(question);
    await panel.getByRole('button', { name: 'Ask' }).click();
    await expect(panel.getByText('Your questions, awaiting moderation')).toBeVisible();
    await expect(panel).toContainText(question);
    await shot(page, 'live-panel-qa');
    await expectNoViolations(page, 'live page with the side panel');

    // Emoji: before the player opens they sit under the stream; a tap floats one up.
    await page.getByRole('button', { name: 'React: Love it' }).click();
    await expect(page.locator('.floating-emoji').first()).toBeAttached();

    // Collapse; it stays collapsed after a reload, and reopens.
    await panel.getByRole('button', { name: 'Hide chat and questions' }).click();
    await expect(panel).toBeHidden();
    const content2 = await page.locator('[data-stage-content]').boundingBox();
    expect(Math.round(content2.width)).toBe(Math.round(stage.width));
    await page.reload();
    await settle(page);
    await expect(page.locator('[data-side-panel]')).toBeHidden();
    await page.getByRole('button', { name: /^Chat & Q&A/ }).click();
    await expect(page.locator('[data-side-panel]')).toBeVisible();
});

test('on a phone, the panel and reactions sit under the stream', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, ATTENDEE, /\/dashboard/);
    await page.goto('/live');
    await settle(page);

    await expect(page.locator('[data-side-panel]')).toHaveCount(0);
    const tabs = page.getByRole('tablist', { name: 'Chat and questions' });
    await expect(tabs).toBeVisible();
    const stage = await page.locator('[data-player]').boundingBox();
    expect((await tabs.boundingBox()).y).toBeGreaterThan(stage.y + stage.height);
    await expect(page.getByRole('button', { name: 'React: Thumbs up' })).toBeVisible();
    const sideways = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(sideways).toBe(false);
    await shot(page, 'live-phone');
    await expectNoViolations(page, 'live page on a phone');
});

test('a poll launched by staff pops up over the stream, and attendees answer it there', async ({ page, browser }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await login(page, ADMIN, /\/admin/);
    const live = await workspaceLive(page);

    // Staff open the player early so the stream controls (and emoji) are there.
    await page.goto('/admin/live/control-room');
    await settle(page);
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Open the player now' }).click();
    await expect(page.getByText('Open early: registered attendees can join now.')).toBeVisible();

    await page.goto(`${live}/polls`);
    await settle(page);
    const seeded = page.locator('div.shadow', { hasText: 'E2E poll: which topic next?' });
    await seeded.getByRole('button', { name: 'Open' }).click();
    await expect(seeded.getByText('Open now')).toBeVisible();

    const attendeeContext = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const attendee = await attendeeContext.newPage();
    await login(attendee, ATTENDEE, /\/dashboard/);
    await attendee.goto('/live');
    await settle(attendee);

    const card = attendee.locator('[data-player] section', { hasText: 'E2E poll: which topic next?' });
    await expect(card).toBeVisible();
    await shot(attendee, 'live-poll-card');
    await expectNoViolations(attendee, 'live page with a poll over the stream');

    await card.getByRole('button', { name: 'Data governance' }).click();
    await expect(card.getByText('Thanks for voting.')).toBeVisible();
    // This poll keeps its results until it closes.
    await expect(card.getByText('Results appear when the poll closes.')).toBeVisible();

    // Emoji now sit in the player's control bar.
    await attendee.locator('[data-player]').hover();
    await attendee.locator('[data-player-controls]').getByRole('button', { name: 'React: Applause' }).click();
    await expect(attendee.locator('.floating-emoji').first()).toBeAttached();
    await shot(attendee, 'live-poll-voted');

    // Staff see the vote on the Polls page, and close the poll.
    await page.reload();
    await settle(page);
    await expect(seeded).toContainText('1 vote');
    await seeded.getByRole('button', { name: 'Close' }).click();
    await expect(seeded.getByText('Closed')).toBeVisible();

    // Tidy up for other specs: close the player again.
    await page.goto('/admin/live/control-room');
    await settle(page);
    await page.getByRole('button', { name: 'Close the player' }).click();
    await expect(page.getByRole('button', { name: 'Open the player now' })).toBeVisible();
    await attendeeContext.close();
});

test('staff moderate questions and chat live; the console runs polls without leaving the page', async ({ page }) => {
    await page.setViewportSize({ width: 1366, height: 900 });
    await login(page, ADMIN, /\/admin/);
    const live = await workspaceLive(page);

    await page.goto(`${live}/questions`);
    await settle(page);
    await expect(page.getByRole('heading', { name: 'Moderate Q&A and chat' })).toBeVisible();
    await expect(page.getByText('Waiting for approval')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Live chat' })).toBeVisible();
    await shot(page, 'admin-moderation');
    await expectNoViolations(page, 'moderation page');

    await page.goto(`${live}/broadcast`);
    await settle(page);
    const audience = page.locator('div.shadow', { has: page.getByRole('heading', { name: 'Audience' }) });
    await expect(audience.getByRole('tab', { name: /Polls/ })).toBeVisible();

    const question = `Console poll ${Date.now()}`;
    await audience.getByRole('button', { name: 'New poll' }).click();
    await audience.getByRole('textbox', { name: 'Question', exact: true }).fill(question);
    await audience.getByRole('textbox', { name: 'Option 1', exact: true }).fill('Yes');
    await audience.getByRole('textbox', { name: 'Option 2', exact: true }).fill('No');
    await audience.getByRole('button', { name: 'Launch now' }).click();
    await expect(audience.getByText(`Live now: ${question}`)).toBeVisible();
    // Still on the console: nothing navigated away (that would take a presenter off air).
    expect(page.url()).toMatch(/\/broadcast$/);
    await shot(page, 'broadcast-console-poll');
    await expectNoViolations(page, 'broadcast console with a live poll');

    await audience.getByRole('button', { name: 'Close poll' }).click();
    await expect(audience.getByText(`Live now: ${question}`)).toHaveCount(0);
    await audience.getByRole('tab', { name: /Q&A/ }).click();
    await expect(audience).toContainText('E2E approved question?');
});

test('a speaker runs a poll from their stage link', async ({ page, browser }) => {
    await login(page, ADMIN, /\/admin/);
    const live = await workspaceLive(page);
    await page.goto(`${live}/broadcast`);
    await settle(page);

    const speakerRow = page.locator('li', { has: page.getByRole('button', { name: 'Create link' }) }).first();
    const speakerName = (await speakerRow.locator('span.font-medium').first().innerText()).trim();
    const row = page.locator('li', { hasText: speakerName });
    await row.getByRole('button', { name: 'Create link' }).click();
    const stageUrl = (await row.locator('dd span.break-all').innerText()).trim();

    const speakerContext = await browser.newContext();
    const speaker = await speakerContext.newPage();
    await speaker.goto(stageUrl);
    await settle(speaker);

    const panel = speaker.locator('[aria-labelledby="stage-audience-title"]');
    await expect(panel.getByRole('tab', { name: /Polls/ })).toBeVisible();
    const question = `Speaker poll ${Date.now()}`;
    await panel.getByRole('button', { name: 'New poll' }).click();
    await panel.getByRole('textbox', { name: 'Question', exact: true }).fill(question);
    await panel.getByRole('textbox', { name: 'Option 1', exact: true }).fill('Agree');
    await panel.getByRole('textbox', { name: 'Option 2', exact: true }).fill('Disagree');
    await panel.getByRole('button', { name: 'Launch now' }).click();
    await expect(panel.getByText(`Live now: ${question}`)).toBeVisible();
    await shot(speaker, 'speaker-stage-poll');
    await expectNoViolations(speaker, 'speaker stage with a live poll');

    // The speaker sees approved questions only, never the moderation queue.
    await panel.getByRole('tab', { name: /Q&A/ }).click();
    await expect(panel).toContainText('E2E approved question?');
    await expect(panel).not.toContainText('E2E pending question?');

    await panel.getByRole('tab', { name: /Polls/ }).click();
    await panel.getByRole('button', { name: 'Close poll' }).click();
    await expect(panel.getByText(`Live now: ${question}`)).toHaveCount(0);
    await speakerContext.close();

    page.once('dialog', (d) => d.accept());
    await row.getByRole('button', { name: 'Withdraw' }).click();
    await expect(row.getByRole('button', { name: 'Create link' })).toBeVisible();
});
