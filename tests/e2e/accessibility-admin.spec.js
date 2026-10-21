import { test, expect } from '@playwright/test';
import { ADMIN, adminUrls, login, scanAll } from './support';

// Every admin GET page, reported together so one run shows all the problems.
test('every admin page has no axe violations', async ({ page }) => {
    test.setTimeout(1_200_000); // ~40 pages, each loaded and scanned (420s ran out on a slow machine)
    await login(page, ADMIN);

    expect(await scanAll(page, await adminUrls(page))).toEqual([]);
});
