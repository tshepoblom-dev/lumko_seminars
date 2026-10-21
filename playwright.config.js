import { defineConfig, devices } from '@playwright/test';

const baseURL = 'http://127.0.0.1:8791';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    // `php artisan serve` is single-threaded on Windows, so a login POST can
    // queue behind the app's parallel chunk prefetch; production runs php-fpm.
    timeout: 45_000,
    expect: { timeout: 15_000 },
    reporter: [['list']],
    use: {
        baseURL,
        // Third-party chat (Tawk.to) is not ours to test: point its host at
        // nothing so the axe/layout checks cover only this app's markup and
        // runs don't depend on an external network call.
        //
        // A synthetic camera, mic and screen, granted without a prompt, for
        // the Agora presenter pages (livestream.spec.js). Only takes effect
        // when a page asks for media; kept global because a per-spec
        // launchOptions override gets its own worker, which runs after
        // zz-post-event has moved the event into the past.
        launchOptions: {
            args: [
                '--host-resolver-rules=MAP embed.tawk.to 0.0.0.0',
                '--use-fake-device-for-media-stream',
                '--use-fake-ui-for-media-stream',
                '--auto-select-desktop-capture-source=Entire screen',
            ],
        },        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    // Rebuilds the dedicated SQLite testing database from scratch before
    // every run — this suite never touches the real MySQL dev database
    // (see .env.testing). Playwright starts this, waits for baseURL to
    // respond, and tears it down after the run.
    webServer: {
        command: 'php artisan cache:clear --env=testing && php artisan migrate:fresh --seed --env=testing --force && php artisan e2e:seed --env=testing && php artisan serve --env=testing --port=8791',
        url: baseURL,
        reuseExistingServer: false,
        timeout: 60_000,
        stdout: 'ignore',
        stderr: 'ignore',
    },
});
