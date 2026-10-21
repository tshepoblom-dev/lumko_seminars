import { test, expect } from '@playwright/test';

test('delete-speaker modal traps focus, closes on Escape and restores focus', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('admin@lumko.cloud');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/admin/);

    await page.goto('/admin/speakers');
    const trigger = page.getByRole('button', { name: 'Delete' }).first();
    await trigger.click();

    const dialog = page.getByRole('dialog', { name: 'Delete speaker' });
    await expect(dialog).toBeVisible();

    // Focus moves into the dialog and Tab never escapes it.
    await expect(dialog.locator(':focus')).toHaveCount(1);
    for (let i = 0; i < 6; i++) {
        await page.keyboard.press('Tab');
        await expect(dialog.locator(':focus')).toHaveCount(1);
    }
    for (let i = 0; i < 6; i++) {
        await page.keyboard.press('Shift+Tab');
        await expect(dialog.locator(':focus')).toHaveCount(1);
    }

    // Escape closes it and focus returns to the button that opened it.
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('cancelling the delete modal deletes nothing', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email').fill('admin@lumko.cloud');
    await page.getByLabel('Password', { exact: true }).fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/admin/);

    await page.goto('/admin/speakers');
    const before = await page.getByRole('button', { name: 'Delete' }).count();

    await page.getByRole('button', { name: 'Delete' }).first().click();
    await page.getByRole('dialog').getByRole('button', { name: 'Cancel' }).click();

    await expect(page.getByRole('dialog')).toBeHidden();
    expect(await page.getByRole('button', { name: 'Delete' }).count()).toBe(before);
});
