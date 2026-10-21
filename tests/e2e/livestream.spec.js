import { test, expect } from '@playwright/test';
import { ADMIN, ATTENDEE, expectNoViolations, login, settle } from './support';

// Remote speaker stage links, the staff attendee view, screen + camera, and
// the "when does the player open" states. The e2e event streams through
// Agora (e2e:seed) and is in the future, so the live window isn't open yet.
// There are no Agora credentials here: nothing actually joins a channel —
// the camera/screen capture and the page states are what's exercised.

const SHOTS = process.env.LIVESTREAM_SHOTS_DIR;
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }) : null);

// Fake camera/mic/screen flags are set globally in playwright.config.js.


test('before the live window, attendees are told when the player opens', async ({ page }) => {
    await login(page, ATTENDEE, /\/dashboard/);
    await settle(page);

    const card = page.locator('section', { hasText: 'Watching online' });
    await expect(card).toBeVisible();
    await expect(card).toContainText(/The live player opens at \d{2}:\d{2} on \w+day \d{1,2} \w+ \d{4}, on the Live page\./);
    await shot(page, 'dashboard-before-window');

    await page.goto('/live');
    await settle(page);
    await expect(page.getByText(/^The live player opens at \d{2}:\d{2}\.$/)).toBeVisible();
    // No Join button whose token request would be refused.
    await expect(page.getByRole('button', { name: 'Join live session' })).toHaveCount(0);
    await shot(page, 'live-before-window');
    await expectNoViolations(page, 'live page before the window');
});

test('staff create a speaker stage link, and the speaker can check camera and share a screen', async ({ page, browser }) => {
    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/live/control-room');
    await settle(page);

    // The attendee view opens in a new tab so the console never goes off air.
    const attendeeView = page.getByRole('link', { name: /Open attendee view/ });
    await expect(attendeeView).toHaveAttribute('target', '_blank');
    const previewUrl = await attendeeView.getAttribute('href');
    expect(previewUrl).toMatch(/\/admin\/events\/\d+\/live\/preview$/);

    await page.getByRole('link', { name: 'Open broadcast console' }).click();
    await settle(page);
    await expect(page.getByRole('heading', { name: 'Remote speakers' })).toBeVisible();

    // The host token request gets past CSRF. The console used to send an
    // empty X-CSRF-TOKEN from a meta tag the layout doesn't have (→ 419);
    // axios sends the XSRF cookie back. Agora has no credentials here, so
    // reaching the controller shows up as its "not configured" 500.
    const tokenUrl = page.url().replace(/\/broadcast$/, '/broadcast/token');
    const statuses = await page.evaluate(async (url) => {
        const old = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': '', Accept: 'application/json' } });
        const now = await window.axios.post(url, {}, { headers: { Accept: 'application/json' } }).catch((e) => e.response);
        return { old: old.status, now: now.status, message: now.data?.message };
    }, tokenUrl);
    expect(statuses.old).toBe(419);
    expect(statuses.now).toBe(500);
    expect(statuses.message).toBe('Agora is not configured.');

    const speakerName = (await page.locator('li', { has: page.getByRole('button', { name: 'Create link' }) })
        .first().locator('span.font-medium').first().innerText()).trim();
    // Pinned by name: once its button reads "New link" it no longer matches the filter above.
    const firstSpeaker = page.locator('li', { hasText: speakerName });
    await firstSpeaker.getByRole('button', { name: 'Create link' }).click();

    const linkValue = firstSpeaker.locator('dd span.break-all');
    await expect(linkValue).toContainText('/stage/');
    const stageUrl = (await linkValue.innerText()).trim();
    await expect(firstSpeaker.getByRole('button', { name: 'New link' })).toBeVisible();
    await shot(page, 'broadcast-console-remote-speakers');
    await expectNoViolations(page, 'broadcast console');

    // Staff preview of /live for this event.
    await page.goto(previewUrl);
    await settle(page);
    await expect(page.getByRole('note')).toContainText('Staff preview of what attendees see');
    await expect(page.getByRole('heading', { name: 'The Power of Data' })).toBeVisible();
    // Staff can always join, so the Join button shows even before the window.
    await expect(page.getByRole('button', { name: 'Join live session' })).toBeVisible();
    await shot(page, 'attendee-view-preview');

    // The speaker: signed out, straight from the link.
    const speakerContext = await browser.newContext();
    const speaker = await speakerContext.newPage();
    await speaker.goto(stageUrl);
    await settle(speaker);

    await expect(speaker.getByRole('heading', { name: `Welcome, ${speakerName}` })).toBeVisible();
    await expect(speaker.getByRole('button', { name: 'Join the stage' })).toBeVisible();
    const robots = await speaker.locator('meta[name="robots"]').getAttribute('content');
    expect(robots).toBe('noindex, nofollow');
    await expectNoViolations(speaker, 'speaker stage');

    await speaker.getByRole('button', { name: 'Check camera and mic' }).click();
    await expect(speaker.getByRole('button', { name: 'Mute mic' })).toBeVisible();

    // The fake camera is really playing in the preview, full-frame.
    const cameraVideo = speaker.locator('[data-preview="camera"] video');
    await expect(cameraVideo).toBeVisible();
    const camBox = await cameraVideo.boundingBox();
    expect(camBox.width).toBeGreaterThan(300);

    await speaker.getByRole('button', { name: 'Share screen (slides)' }).click();
    await expect(speaker.getByRole('button', { name: 'Stop sharing screen' })).toBeVisible();

    // Screen fills the frame; the camera drops to a corner tile.
    const videos = speaker.locator('[data-preview] video');
    await expect(speaker.locator('[data-preview="screen"] video')).toBeVisible();
    await expect(videos).toHaveCount(2);
    await expect.poll(async () => (await cameraVideo.boundingBox()).width).toBeLessThan(camBox.width / 2);
    await shot(speaker, 'speaker-stage-screen-share');

    await speaker.getByRole('button', { name: 'Stop sharing screen' }).click();
    await expect(videos).toHaveCount(1);
    await speakerContext.close();

    // Withdraw it again so reruns and other specs start clean.
    await page.goto(previewUrl.replace(/\/preview$/, '/broadcast'));
    await settle(page);
    page.once('dialog', (d) => d.accept());
    await page.locator('li', { hasText: speakerName }).getByRole('button', { name: 'Withdraw' }).click();
    await expect(page.locator('li', { hasText: speakerName }).getByRole('button', { name: 'Create link' })).toBeVisible();

    const gone = await (await browser.newContext()).request.get(stageUrl);
    expect(gone.status()).toBe(404);
});

// The camera held by another app (Teams, Zoom…): getUserMedia for video
// fails with NotReadableError until window.__cameraBusy is cleared. The
// mic and screen capture keep working.
function holdCameraElsewhere(target) {
    return target.addInitScript(() => {
        const real = navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
        window.__cameraBusy = true;
        navigator.mediaDevices.getUserMedia = (constraints) => (window.__cameraBusy && constraints?.video
            ? Promise.reject(new DOMException('Could not start video source', 'NotReadableError'))
            : real(constraints));
    });
}

test('with the camera in use by another app, presenters can still go on with mic and screen', async ({ page, browser }) => {
    // Staff console: the busy camera no longer stops "Start broadcast".
    await holdCameraElsewhere(page);
    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/live/control-room');
    await settle(page);
    await page.getByRole('link', { name: 'Open broadcast console' }).click();
    await settle(page);

    await page.getByRole('button', { name: 'Preview camera and mic' }).click();
    const consoleNotice = page.getByRole('status').filter({ hasText: 'camera is in use by another app' });
    await expect(consoleNotice).toContainText('You can still go on air and share your screen.');
    await expect(page.getByRole('button', { name: 'Mute mic' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Turn camera on' })).toBeVisible();
    await expect(page.getByText('No camera — attendees see your name')).toBeVisible();
    await expect(page.getByRole('listitem').filter({ hasText: 'Microphone detected' })).toContainText('✓');
    await expect(page.getByRole('listitem').filter({ hasText: 'Camera detected' })).toContainText('✗');

    // Going on air now gets past the devices to the token request, which
    // fails here only because Agora has no credentials in the test env.
    await page.getByRole('button', { name: 'Start broadcast' }).click();
    await expect(page.getByRole('alert')).toContainText('Could not get permission to go on air.');
    await expect(page.getByRole('alert')).not.toContainText('camera');
    await shot(page, 'broadcast-console-camera-busy');
    await expectNoViolations(page, 'broadcast console, camera busy');

    // A remote speaker with the same problem.
    const speakerName = (await page.locator('li', { has: page.getByRole('button', { name: 'Create link' }) })
        .first().locator('span.font-medium').first().innerText()).trim();
    const speakerRow = page.locator('li', { hasText: speakerName });
    await speakerRow.getByRole('button', { name: 'Create link' }).click();
    const stageUrl = (await speakerRow.locator('dd span.break-all').innerText()).trim();

    const speakerContext = await browser.newContext();
    await holdCameraElsewhere(speakerContext);
    const speaker = await speakerContext.newPage();
    await speaker.goto(stageUrl);
    await settle(speaker);

    await speaker.getByRole('button', { name: 'Check camera and mic' }).click();
    await expect(speaker.getByRole('status').filter({ hasText: 'camera is in use by another app' })).toBeVisible();
    await expect(speaker.getByRole('button', { name: 'Mute mic' })).toBeVisible();
    await expect(speaker.getByText('No camera. Attendees see your name.')).toBeVisible();

    // Screen share on its own: the screen fills the frame, no empty camera tile.
    await speaker.getByRole('button', { name: 'Share screen (slides)' }).click();
    await expect(speaker.locator('[data-preview="screen"] video')).toBeVisible();
    await expect(speaker.locator('[data-preview="camera"]')).toBeHidden();
    await shot(speaker, 'speaker-stage-camera-busy-screen-share');
    await expectNoViolations(speaker, 'speaker stage, camera busy');
    await speaker.getByRole('button', { name: 'Stop sharing screen' }).click();

    // The other app lets go: "Turn camera on" tries again and it starts.
    await speaker.evaluate(() => { window.__cameraBusy = false; });
    await speaker.getByRole('button', { name: 'Turn camera on' }).click();
    await expect(speaker.getByRole('button', { name: 'Turn camera off' })).toBeVisible();
    await expect(speaker.locator('[data-preview="camera"] video')).toBeVisible();
    await expect(speaker.getByText('camera is in use by another app')).toHaveCount(0);
    await expect(speaker.getByRole('listitem').filter({ hasText: 'Camera found' })).toContainText('✓');
    await shot(speaker, 'speaker-stage-camera-recovered');
    await speakerContext.close();

    page.once('dialog', (d) => d.accept());
    await speakerRow.getByRole('button', { name: 'Withdraw' }).click();
    await expect(speakerRow.getByRole('button', { name: 'Create link' })).toBeVisible();
});

test('while the event is live, the home page leads to the live session', async ({ page, browser }) => {
    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/live/control-room');
    await settle(page);
    await page.getByRole('button', { name: 'Go live' }).click();
    await expect(page.getByRole('button', { name: 'End live' })).toBeVisible();

    const visitor = await (await browser.newContext()).newPage();
    await visitor.goto('/');
    await settle(visitor);
    const strip = visitor.getByRole('link', { name: /LIVE NOW/ });
    await expect(strip).toBeVisible();
    await expect(strip).toHaveAttribute('href', /\/live$/);
    await shot(visitor, 'home-live-strip');

    await page.getByRole('button', { name: 'End live' }).click();
    await expect(page.getByRole('button', { name: 'Go live' })).toBeVisible();

    await visitor.reload();
    await settle(visitor);
    await expect(visitor.getByRole('link', { name: /LIVE NOW/ })).toHaveCount(0);
});

test('staff can open the player early for a rehearsal, and attendees can join', async ({ page, browser }) => {
    const attendeeContext = await browser.newContext();
    const attendee = await attendeeContext.newPage();
    await login(attendee, ATTENDEE, /\/dashboard/);
    await attendee.goto('/live');
    await settle(attendee);
    await expect(attendee.getByText(/^The live player opens at \d{2}:\d{2}\.$/)).toBeVisible();

    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/live/control-room');
    await settle(page);
    page.once('dialog', (d) => d.accept());
    await page.getByRole('button', { name: 'Open the player now' }).click();
    await expect(page.getByText('Open early: registered attendees can join now.')).toBeVisible();
    await shot(page, 'control-room-player-open');

    // In production this arrives live over Pusher (the session.updated
    // payload carries player_open; see PlayerOpenTest). The browser suite
    // can't observe that: the build may carry a real Pusher key while the
    // test server only logs broadcasts. So reload.
    await attendee.reload();
    await settle(attendee);
    await expect(attendee.getByRole('button', { name: 'Join live session' })).toBeVisible();

    // Full screen: the player fills the screen, with its controls and the
    // reactions floating over it, fading out while nobody moves.
    const player = attendee.locator('[data-player]');
    const controls = attendee.locator('[data-player-controls]');
    await attendee.getByRole('button', { name: 'Full screen', exact: true }).click();
    await expect.poll(() => attendee.evaluate(() => document.fullscreenElement?.hasAttribute('data-player') ?? false)).toBe(true);
    const viewport = attendee.viewportSize();
    const fullBox = await player.boundingBox();
    expect(Math.round(fullBox.width)).toBe(viewport.width);
    expect(Math.round(fullBox.height)).toBe(viewport.height);
    await expect(controls.getByRole('button', { name: 'React: Thumbs up' })).toBeVisible();
    await expect(controls.getByRole('button', { name: 'React: Thought-provoking' })).toBeVisible();
    await shot(attendee, 'live-fullscreen-controls');
    await expectNoViolations(attendee, 'live player in full screen');

    // Park the mouse off the bar, then leave it still.
    await attendee.mouse.move(viewport.width / 2, viewport.height / 4);
    await expect.poll(() => controls.evaluate((el) => getComputedStyle(el).opacity), { timeout: 6000 }).toBe('0');
    await shot(attendee, 'live-fullscreen-idle');
    await attendee.mouse.move(viewport.width / 2 + 40, viewport.height / 4 + 40);
    await expect.poll(() => controls.evaluate((el) => getComputedStyle(el).opacity)).toBe('1');

    await attendee.getByRole('button', { name: 'Exit full screen' }).click();
    await expect.poll(() => attendee.evaluate(() => !!document.fullscreenElement)).toBe(false);
    const box = await player.boundingBox();
    expect(Math.abs(box.width / box.height - 16 / 9)).toBeLessThan(0.02);

    await page.getByRole('button', { name: 'Close the player' }).click();
    await expect(page.getByRole('button', { name: 'Open the player now' })).toBeVisible();
    await attendee.reload();
    await settle(attendee);
    await expect(attendee.getByText(/^The live player opens at \d{2}:\d{2}\.$/)).toBeVisible();
    await attendeeContext.close();
});
