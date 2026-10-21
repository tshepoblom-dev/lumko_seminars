import { test, expect } from '@playwright/test';

// The Tawk.to chat script is requested only on the pages meant to have it;
// never on the registration stepper (the bubble covered "Next" on phones)
// or sign-in pages. The widget itself is blocked in e2e (playwright.config),
// so this checks our loading decision, not Tawk's markup.
test('the chat widget loads on the home page but not on registration or login', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#tawk-script')).toHaveCount(1);

    await page.goto('/attend');
    await expect(page.locator('#tawk-script')).toHaveCount(0);

    await page.goto('/login');
    await expect(page.locator('#tawk-script')).toHaveCount(0);
});
