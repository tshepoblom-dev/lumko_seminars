<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduler
|--------------------------------------------------------------------------
|
| A single cron/Task Scheduler entry drives all of this:
|   * * * * * php artisan schedule:run
|
| Stage D adds reminders:send, resources:release, postevent:send and
| announcements:publish here alongside these two.
|
*/

// C6 — opens/closes polls on their scheduled opens_at/closes_at time.
Schedule::command('polls:tick')->everyMinute()->withoutOverlapping();

// B3 — deletes PoP files (not the payment record) past the retention window.
Schedule::command('payments:purge-proofs')->daily()->withoutOverlapping();

// D-Announcements — in-app (not email) notification once published_at arrives.
Schedule::command('announcements:publish')->everyMinute()->withoutOverlapping();

// D-Notifications — 24h/1h/starting-soon reminders, each idempotent per
// attendee per event via the sent_notifications ledger.
Schedule::command('reminders:send')->everyFiveMinutes()->withoutOverlapping();

// D-Resources — releases a resource (sets released_at, notifies once)
// the moment its release_mode/release_at/session timing is satisfied.
Schedule::command('resources:release')->everyMinute()->withoutOverlapping();

// D-Notifications — post-event thank-you/feedback prompt.
Schedule::command('postevent:send')->everyTenMinutes()->withoutOverlapping();

// Admin → Analytics — drops stale "live now" presence rows and page hits
// past seminar.analytics.retention_days.
Schedule::command('analytics:prune')->hourly()->withoutOverlapping();

// Drains the database queue (every notification is ShouldQueue) — the
// single schedule:run cron is the only process runner on this host.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()->withoutOverlapping();
