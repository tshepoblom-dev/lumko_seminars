import { test, expect } from '@playwright/test';
import { ATTENDEE, IN_PERSON_PAID, IN_PERSON_PENDING, login } from './support';

// Value-level checks for the visual redesign. axe passes whether or not the
// facts are on the page, so assert on what is actually rendered.

test('the landing page states when and where, in the event timezone', async ({ page }) => {
    await page.goto('/');

    const hero = page.locator('section[aria-labelledby="event-title"]');
    await expect(hero.getByRole('heading', { level: 1 })).toHaveText('The Power of Data');
    // Before the redesign the page never showed the date, time or address.
    await expect(hero).toContainText(/\b\w+day \d{1,2} \w+ \d{4}\b/);
    await expect(hero).toContainText('08:00–09:20');
    await expect(hero).toContainText("St Eve's Spa & Lodge");
    await expect(hero).toContainText('21 York Street, Waverley, Bloemfontein');

    // The programme is for registered attendees: not on the public page.
    await expect(page.locator('#programme')).toHaveCount(0);
    await expect(page.locator('main figure[aria-hidden="true"] .origin-left')).toHaveCount(0);
});

test('the programme is on the attendee dashboard', async ({ page }) => {
    await login(page, ATTENDEE);
    await page.goto('/dashboard');

    const programme = page.locator('section[aria-labelledby="dash-programme-title"]');
    const sessions = await programme.locator('ol > li').count();
    expect(sessions).toBeGreaterThan(0);
    // The ruler draws one segment per session.
    await expect(programme.locator('figure[aria-hidden="true"] .origin-left')).toHaveCount(sessions);
    await expect(programme.getByRole('link', { name: 'Full programme' })).toHaveCount(0);
});

test('the landing page explains the Lumko Seminars series', async ({ page }) => {
    await page.goto('/');

    const series = page.locator('#series');
    await expect(series.getByRole('heading', { level: 2 })).toContainText('Lumko Seminars');
    await expect(series.getByRole('heading', { level: 3 })).toHaveText(['Technology', 'Business', 'Society']);
    // The sectors are examples, so the sentence must not read as the full list.
    await expect(series).toContainText('fields such as LegalTech, EduTech, MiningTech and FinTech');
    await expect(series).toContainText('Hybrid breakfast');

    // It closes the page, after the venue, and the page nav reaches it.
    const order = await page.locator('main section[id]').evaluateAll((els) => els.map((el) => el.id));
    expect(order.at(-1)).toBe('series');
    expect(order.indexOf('venue')).toBe(order.length - 2);
    await expect(series.locator('.section-index')).toContainText('05');
    await page.getByRole('navigation', { name: 'On this page' }).getByRole('link', { name: 'The series' }).click();
    await expect(page).toHaveURL(/#series$/);

    await expect(series.getByRole('link', { name: 'Sign in to follow the series' })).toHaveAttribute('href', /\/login$/);
});

test('a confirmed ticket names the attendee and the venue', async ({ page }) => {
    await login(page, IN_PERSON_PAID);
    await page.goto('/ticket');

    const ticket = page.getByRole('article', { name: /Ticket for/ });
    await expect(ticket).toContainText('E2E CheckIn');
    await expect(ticket).toContainText("St Eve's Spa & Lodge");
    await expect(ticket).toContainText('08:00–09:20');
    await expect(ticket.getByTestId('ticket-code')).toHaveText('E2ETEST1');
});

test('the payment page shows the reference with a working copy button', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await login(page, IN_PERSON_PENDING);
    await page.goto('/payment');

    const copy = page.getByRole('button', { name: 'Copy reference' });
    await expect(copy).toBeVisible();

    // The reference value sits beside its copy button.
    const reference = (await copy.locator('xpath=..').locator('span.block').first().textContent()).trim();
    expect(reference).not.toBe('');

    await copy.click();
    await expect(copy).toContainText('Copied');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(reference);

    // The upload area keeps its real, labelled file input.
    await expect(page.getByLabel(/^Upload proof of payment \(JPG/)).toHaveAttribute('type', 'file');
});
