# Deployment guide

Written for a Linux server (Debian/Ubuntu) running nginx + PHP-FPM + MySQL/MariaDB, with Supervisor for the queue worker. The hosting environment has not been confirmed, so Windows equivalents are noted where they differ. Anything marked **verify** is something I could not test from the development machine.

## 1. Requirements

| Item | Requirement |
|---|---|
| PHP | 8.2 or newer |
| PHP extensions | `ctype curl dom fileinfo filter gd hash iconv json libxml mbstring openssl pcre phar session tokenizer xml xmlwriter` (from `composer check-platform-reqs`) plus `pdo_mysql` |
| Database | MySQL 8 / MariaDB 10.6+ (SQLite is for tests only) |
| Web server | Any that can serve `public/` and pass PHP to FPM |
| Node | Only to **build** assets (Node 20+). Build in CI or locally and upload `public/build`; Node is not needed at runtime |
| Process manager | Supervisor or systemd (queue worker) |

## 2. Web server and PHP

- **Document root must be `public/`.** Never expose the project root — `.env`, `storage/` and `vendor/` would be downloadable.
- HTTPS is mandatory (secure session cookies, camera/microphone for the Agora host console and the check-in scanner). The app sends HSTS itself once it sees an HTTPS request in production.
- If TLS is terminated by a proxy/load balancer, set `TRUSTED_PROXIES` in `.env` (see `config/trustedproxy.php`). Without it the email-verification and confirm-registration links are generated as `http://` and their signatures fail.

`php.ini` (FPM):

```ini
upload_max_filesize = 25M   ; resources are capped at 20 MB by the app
post_max_size       = 30M
memory_limit        = 256M
max_execution_time  = 60
```

Minimal nginx server block:

```nginx
server {
    listen 443 ssl http2;
    server_name seminar.lumko.cloud;
    root /var/www/seminar/public;
    index index.php;
    client_max_body_size 30M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }
    location ~ /\.(?!well-known) { deny all; }
}
```

## 3. First deploy

```bash
git clone <repo> /var/www/seminar && cd /var/www/seminar   # or upload a release archive
composer install --no-dev --optimize-autoloader
cp .env.example .env            # then fill it in — see the comments in the file
php artisan key:generate
# assets: build on CI/your machine (`npm ci && npm run build`) and upload public/build
php artisan migrate --force
php artisan db:seed --force     # creates the published event; in production it does NOT create an admin
php artisan storage:link
php artisan lumko:create-admin you@yourdomain.com        # see §8
rm -f bootstrap/cache/*.php     # stale caches from a different machine cause odd errors
php artisan optimize            # config, route, view, event cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

Then set up the queue worker (§4) and the scheduler (§5) **before** opening registration.

**PDF tickets.** The ticket PDF (emailed on payment approval and invitation-code redemption, downloadable at `/ticket/pdf`) is rendered by dompdf with the fonts in `resources/fonts/pdf` (OFL). dompdf caches font metrics in `storage/fonts`, which the `chmod` above covers; the first ticket after a deploy takes a few seconds while that cache is built. Render one as a smoke test: sign in as a confirmed in-person attendee and use **Download PDF** on the ticket page. With `MAIL_MAILER=log` (development only) whole emails, PDF included, land in `storage/logs`.

## 4. Queue worker (mandatory)

Registration-confirmation, payment, ticket, reminder, announcement and networking emails/notifications are all queued. **With no worker running, none of them is sent** — attendees will register and never receive their confirmation link.

Supervisor (`/etc/supervisor/conf.d/seminar-worker.conf`):

```ini
[program:seminar-worker]
command=php /var/www/seminar/artisan queue:work --tries=3 --backoff=30 --max-time=3600
directory=/var/www/seminar
user=www-data
autostart=true
autorestart=true
stopwaitsecs=3600
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/seminar-worker.log
```

- `--max-time=3600` makes the worker exit hourly so Supervisor restarts it with fresh code/config.
- **After every deploy or `.env` change: `php artisan queue:restart`.**
- Failed jobs land in the `failed_jobs` table: check `php artisan queue:failed` daily during the event window; retry with `php artisan queue:retry all`.
- Windows: run the same command under NSSM or a scheduled task set to restart on failure. (**verify** — untested.)
- The admin dashboard warns when queued jobs have waited 10+ minutes (the worker is down) or jobs have failed.

**Checking email on the server** (the registration link is how people set their first password, so this must work):

1. `supervisorctl status seminar-worker` shows RUNNING; `php artisan queue:failed` is empty.
2. `.env`: `APP_URL=https://seminar.lumko.cloud` (the links in every email are built from it), `SESSION_SECURE_COOKIE=true`, `MAIL_*` set. Then `php artisan config:cache && php artisan queue:restart`.
3. Send a test: `php artisan tinker --execute="Illuminate\Support\Facades\Notification::route('mail','you@example.com')->notify(new Illuminate\Auth\Notifications\ResetPassword('test'));"`, then check the inbox **and** spam folder. An SMTP error prints here and in `storage/logs`.
4. DNS: SPF and DKIM for the sending domain must cover the mail server (`mail.lumko.cloud`); check a delivered message's headers show `spf=pass` and `dkim=pass`.
5. Register a real test address through `/attend`, click the link, set a password, then try **Forgot password**.

"Forgot password" emails are sent immediately (not queued); if the mail server refuses, the page asks the person to try again rather than showing an error page.
## 5. Scheduler (mandatory)

One cron entry runs everything:

```cron
* * * * * cd /var/www/seminar && php artisan schedule:run >> /dev/null 2>&1
```

(Windows: a Task Scheduler task every minute running `php artisan schedule:run`.) Check with `php artisan schedule:list`.

| Command | Runs | What it does | Why running it twice is safe |
|---|---|---|---|
| `polls:tick` | every minute | Opens/closes polls at their scheduled time; enforces one open poll | Only acts on polls whose state doesn't match their schedule |
| `announcements:publish` | every minute | Creates the **in-app** notification when an announcement's `published_at` arrives (never sends email — email is a manual button) | Sets `send_started_at` first and only selects rows where it is null (at-most-once: if the process is killed mid-run, the remaining recipients are not retried) |
| `resources:release` | every minute | Marks a resource released and notifies attendees once its release timing is met | `released_at IS NULL` is the claim |
| `reminders:send` | every 5 min | 24 h / 1 h / "starting soon" reminders | Unique `(user, event, type)` row in `sent_notifications`; `insertOrIgnore` |
| `postevent:send` | every 10 min | Thank-you + feedback prompt after the event ends | Same ledger |
| `payments:purge-proofs` | daily | Deletes proof-of-payment **files** once `SEMINAR_POP_RETENTION_DAYS` have passed since the **event ended** (the payment record stays; each purge is audited) | It clears `proof_path` after deleting, so a purged payment is never selected again |

## 6. Every subsequent deploy

```bash
php artisan down --retry=60          # optional, for schema changes
git pull && composer install --no-dev --optimize-autoloader
# upload the new public/build
php artisan migrate --force
rm -f bootstrap/cache/*.php && php artisan optimize
php artisan queue:restart
php artisan up
```

**Take a database backup first** (§7). `migrate --force` applies every pending migration; there is no rollback safety net in production.

## 7. Storage, backups and retention

| What | Where | Notes |
|---|---|---|
| Database | MySQL | Everything: accounts, registrations, payments, consent ledger, audit log |
| Proofs of payment, resource files | `storage/app/private` (disk `local`) | **Private** — served only through authorised routes |
| Speaker photos | `storage/app/public` → `public/storage` (after `storage:link`) | Public, but only approved photos are ever linked |
| Logs | `storage/logs` | Rotated daily, `LOG_DAILY_DAYS` kept |

Backups (set up by the operator — **nothing in the app does this**):
1. Nightly `mysqldump --single-transaction` of the database, kept off the server.
2. A **pre-event snapshot** (database + `storage/app/private`) the evening before, and a **pre-deploy** dump every time.
3. Back up `storage/app/private` (proofs of payment) alongside the database.
4. **Do a restore test** onto a scratch database/directory before launch and confirm you can log in and open a proof of payment. An untested backup is not a backup.

POPIA notes: an attendee can download all their data and delete (anonymise) their account themselves from `/account`; the anonymiser removes personal details but keeps the minimum financial and consent evidence. Proof-of-payment files expire automatically (§5). The privacy notice itself is a legal document the organiser must supply (`SEMINAR_PRIVACY_NOTICE_URL`).

## 8. Admin accounts

```bash
php artisan lumko:create-admin someone@yourdomain.com                       # super admin
php artisan lumko:create-admin helper@yourdomain.com --role=event_manager   # event manager
php artisan lumko:assign-manager helper@yourdomain.com the-power-of-data    # id or slug; --remove to unassign
```

- `lumko:create-admin` prompts for a strong password (rejects weak/default values) and marks the email verified. **If the email already belongs to an account it updates that account** — new password and the staff role — so double-check the address before running it.
- **An event manager sees no events until assigned** — always run `lumko:assign-manager` after creating one. Super admins see every event.
- Assignments and role changes are written to the audit log (`/admin/audit`).

**Verify no default credentials exist** — the local/testing seeder creates `admin@lumko.cloud` / `password`, and the production seeder must not:

```sql
SELECT id, email, role FROM users WHERE email = 'admin@lumko.cloud';   -- must return 0 rows
```

## 9. Health and monitoring

- `GET /up` returns 200 when the app boots — point an uptime monitor at it.
- Errors show a branded page with no stack trace when `APP_DEBUG=false`; the details are in `storage/logs`. Watch that log and `failed_jobs` during the event.

## 10. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Registrations succeed but no email arrives | Queue worker not running, or mail credentials wrong — check `failed_jobs` and the worker log |
| Confirm/verify links say "Invalid signature" | Behind a proxy without `TRUSTED_PROXIES`, or `APP_URL` doesn't match the public URL |
| "419 / page expired" loops | `SESSION_SECURE_COOKIE=true` on plain http, or `SESSION_DOMAIN` wrong |
| Live page never updates | Pusher keys missing/wrong — the site falls back to polling (works, but slower) |
| Live page says "Live updates paused — refreshing automatically" | Real-time isn't reaching the page, so it's polling every 10 seconds. On the server check `BROADCAST_CONNECTION=pusher` and `PUSHER_APP_ID/KEY/SECRET/CLUSTER` match the Pusher app the build was made with (`VITE_PUSHER_APP_KEY`), then `php artisan config:clear` (or re-cache). In the browser's network tab, `POST /broadcasting/auth` should return 200. `storage/logs` shows any Pusher errors from broadcasts |
| Reminders/releases never fire | Cron entry missing |
| Uploads fail with 413/422 | nginx `client_max_body_size` or php.ini limits below §2 |
