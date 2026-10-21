import { test, expect } from '@playwright/test';
import { ADMIN, expectNoViolations, login, settle } from './support';

// Event → Registration form: staff add their own questions, preview them,
// and a guest answers them on /attend (required choice, text and a file).
// The questions are switched off again at the end, so later specs in the
// same run see the standard form.

const SHOTS = process.env.FORM_SHOTS_DIR;
const shot = (page, name) => (SHOTS ? page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true }) : null);

async function addQuestion(page, { type, label, required = false, options = [], step = 4 }) {
    // One "Add a question" per step card: step 4 first, step 5 second.
    await page.getByRole('button', { name: 'Add a question' }).nth(step === 5 ? 1 : 0).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Type of answer').selectOption(type);
    await dialog.getByLabel('Question', { exact: true }).fill(label);
    if (required) await dialog.getByLabel('People must answer this question').check();
    for (const [i, option] of options.entries()) {
        if (i >= 2) await dialog.getByRole('button', { name: 'Add an option' }).click();
        await dialog.getByLabel(`Option ${i + 1}`, { exact: true }).fill(option);
    }
    await dialog.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Question added.')).toBeVisible();
}

test('staff add questions, and a guest answers them when registering', async ({ page, browser }) => {
    test.setTimeout(600_000);

    await login(page, ADMIN, /\/admin/);
    await page.goto('/admin/events');
    await settle(page);
    const href = await page.locator('a[href*="/admin/events/"][href$="/edit"]').first().getAttribute('href');
    const eventId = href.match(/events\/(\d+)\//)[1];

    await page.goto(`/admin/events/${eventId}/registration-form`);
    await settle(page);
    await expect(page.getByRole('heading', { name: /Step 4:/ })).toBeVisible();
    await expectNoViolations(page, 'registration form editor');

    await addQuestion(page, { type: 'single_choice', label: 'E2E: Which sector are you in?', required: true, options: ['Public', 'Private', 'Non-profit'] });
    await addQuestion(page, { type: 'short_text', label: 'E2E: Your role in data projects' });
    await addQuestion(page, { type: 'file', label: 'E2E: Your company profile' });
    await expect(page.getByText('No questions yet. This step is left out of the form until you add one.')).toBeVisible();
    await addQuestion(page, { type: 'short_text', label: 'E2E: Dietary requirements', step: 5 });
    await shot(page, 'form-editor');

    // Preview: every step, nothing submitted.
    await page.goto(`/admin/events/${eventId}/registration-form/preview`);
    await settle(page);
    await expect(page.getByText('Staff preview of this event')).toBeVisible();

    // A guest registers.
    const guestCtx = await browser.newContext();
    const guest = await guestCtx.newPage();
    await guest.goto('/attend');
    await settle(guest);
    await guest.getByLabel('First name').fill('Form');
    await guest.getByLabel('Last name').fill('Tester');
    await guest.getByLabel('Email').fill('e2e-form@example.test');
    await guest.getByRole('button', { name: 'Next' }).click();
    await guest.getByLabel('Organisation', { exact: true }).fill('Acme Testing Co');
    await guest.getByLabel('Job title').fill('QA Lead');
    await guest.getByRole('button', { name: 'Next' }).click();
    await guest.getByLabel(/Online/).check();
    await guest.getByRole('button', { name: 'Next' }).click();

    // Step 4: the required choice is checked before moving on.
    await expect(guest.getByText(/4\. What would you most like/)).toBeVisible();
    await guest.getByRole('button', { name: 'Next' }).click();
    await expect(guest.getByRole('alert')).toContainText('Please answer “E2E: Which sector are you in?”.');
    await shot(guest, 'register-step4-error');
    await expectNoViolations(guest, 'registration step 4 with an error');

    await guest.getByRole('radio', { name: 'Private' }).check();
    await guest.getByLabel('E2E: Your role in data projects').fill('Data steward');
    await guest.getByLabel('E2E: Your company profile').setInputFiles({
        name: 'profile.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%e2e\n'),
    });
    await shot(guest, 'register-step4');
    await guest.getByRole('button', { name: 'Next' }).click();

    // Step 5 appears because it now has a question.
    await expect(guest.getByText('5. A few more questions')).toBeVisible();
    await guest.getByLabel('E2E: Dietary requirements').fill('Vegetarian');
    await shot(guest, 'register-step5');
    await guest.getByRole('button', { name: 'Next' }).click();
    await expect(guest.getByText('6. Networking')).toBeVisible();
    await guest.getByRole('button', { name: 'Next' }).click();
    await guest.getByRole('button', { name: 'Review' }).click();

    await expect(guest.getByText('Review your registration')).toBeVisible();
    const row = guest.locator('dl > div', { hasText: 'E2E: Which sector are you in?' });
    await expect(row).toContainText('Private');
    await expect(guest.locator('dl > div', { hasText: 'E2E: Your company profile' })).toContainText('profile.pdf');
    await guest.getByRole('checkbox', { name: /I have read and accept the Terms/ }).check();
    await guest.getByRole('button', { name: 'Complete registration' }).click();
    await expect(guest).toHaveURL(/\/attend\/confirmation/);
    await guestCtx.close();

    // Staff see the answers on the attendee's page.
    await page.goto(`/admin/events/${eventId}/attendees?search=e2e-form`);
    await settle(page);
    await page.getByRole('link', { name: 'View Form Tester' }).click();
    await settle(page);
    await expect(page.getByText('Data steward')).toBeVisible();
    await expect(page.getByRole('link', { name: /profile\.pdf \(download\)/ })).toBeVisible();
    await shot(page, 'attendee-answers');

    // Leave the standard form for later specs.
    await page.goto(`/admin/events/${eventId}/registration-form`);
    await settle(page);
    for (const label of ['E2E: Which sector are you in?', 'E2E: Your role in data projects', 'E2E: Your company profile', 'E2E: Dietary requirements']) {
        await page.locator('li', { hasText: label }).getByRole('button', { name: 'Switch off' }).click();
        await expect(page.getByText(/Question switched off/)).toBeVisible();
    }
});
