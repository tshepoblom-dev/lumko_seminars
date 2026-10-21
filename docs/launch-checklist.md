# Launch checklist

Tick every box before opening registration to the public (and again the evening before the event). "Owner" items need a decision or a document from Lumko — the code cannot do them.

## 1. Environment (production server)

- [ ] `APP_ENV=production`, **`APP_DEBUG=false`**, `APP_URL` is the public https URL, `APP_KEY` set (never regenerate it after launch — it decrypts tickets and sessions)
- [ ] `APP_TIMEZONE=Africa/Johannesburg`
- [ ] Served over **HTTPS** only; `SESSION_SECURE_COOKIE=true`
- [ ] Behind a proxy/load balancer? `TRUSTED_PROXIES` set. Test: open the link in a verification email — it must start with `https://` and must not say "invalid signature"
- [ ] **Rate limits make sense for your audience:** `SEMINAR_REGISTRATION_IP_PER_MINUTE` (default 30) — with a proxy in front, `TRUSTED_PROXIES` must be set or every visitor shares the proxy's IP and the cap becomes global (registrations would start failing with 429 for everyone)
- [ ] `php artisan config:cache` / `route:cache` / `view:cache` done (`php artisan optimize`) after the last `.env` change
- [ ] Document root is `public/`; `https://…/.env` and `https://…/storage/logs/laravel.log` both return an error, not the file
- [ ] `LOG_STACK=daily`; the log directory has space and is being watched
- [ ] Uptime monitor on `https://…/up`

## 2. Background processes (the two that silently break email)

- [ ] **Queue worker running** under Supervisor/systemd and set to restart (`php artisan queue:work --tries=3 --backoff=30 --max-time=3600`); `php artisan queue:restart` run after the last deploy
- [ ] **Cron** entry for `schedule:run` every minute; `php artisan schedule:list` shows all six commands
- [ ] `php artisan queue:failed` is empty
- [ ] End-to-end proof: register a real test account → confirmation email arrives within 2 minutes → its link opens **https://seminar.lumko.cloud/confirm-registration/…** (not 127.0.0.1 or http) → set a password → you land on the dashboard. Then **Forgot password** for the same address → reset email arrives → reset works
- [ ] Admin dashboard shows **no** red "emails are waiting" banner (it appears when queued jobs sit unprocessed for 10+ minutes, i.e. the worker isn't running) and no "background jobs failed" banner

## 3. Mail deliverability

- [ ] `MAIL_*` set to a real SMTP account; `MAIL_FROM_ADDRESS` is on a domain you control
- [ ] **SPF, DKIM and DMARC** DNS records published for the sending domain; a test to a Gmail and an Outlook address lands in the inbox, not spam (mail-tester.com or similar)
- [ ] The confirmation, payment-received, payment-approved and reminder emails each read correctly and carry the organiser's legal name and an account link in the footer
- [ ] **PDF ticket:** approve a real test payment (and redeem a test invitation code) → the email arrives with the PDF attached. Print it on A4, then at Admin → Check-in: scan the printed QR with the camera, and on a second test ticket type the printed code by hand (spaces as printed) — both check in. Also download it from the dashboard on a phone

## 4. Accounts and data

- [ ] Staff created with `php artisan lumko:create-admin`; each event manager also assigned with `php artisan lumko:assign-manager`
- [ ] **No default credential:** `SELECT id,email FROM users WHERE email='admin@lumko.cloud';` returns **0 rows** on the production database
- [ ] **Never promote the development database.** The dev database contains `admin@lumko.cloud` / `password`. Production must be built from `migrate` + `db:seed`, not from a dump of dev
- [ ] The seeded event (`php artisan tinker` or Admin → Events) matches the published programme exactly: 08:00–08:05 Welcome, 08:05–08:30 Kgotla Paledi, 08:30–08:55 Dr Herkulaas Combrink, 08:55–09:07 joint discussion, 09:07–09:10 closing, 09:10–09:20 networking; venue, prices (Online free / In person R150) and date (13 Nov 2026) correct
- [ ] Speaker photos, if any, uploaded **and approved**; no invented biographies
- [ ] **Bank details** filled in (`SEMINAR_BANK_*`) — the admin dashboard shows a warning while any are missing; a real in-person test registration shows them on the payment page

## 5. Legal (owner)

- [ ] **Privacy Policy and Terms & Conditions** (`/privacy`, `/terms`) are drafts written from what the platform does. Fill in every `[TO COMPLETE]` through `.env`, then have both reviewed by someone qualified in POPIA and consumer law:
  - `LEGAL_COMPANY_REGISTRATION_NUMBER`, `LEGAL_PHYSICAL_ADDRESS`, `LEGAL_CONTACT_EMAIL`
  - `LEGAL_INFORMATION_OFFICER_NAME`, `LEGAL_INFORMATION_OFFICER_EMAIL` (register the Information Officer with the Information Regulator)
  - `LEGAL_HOSTING_PROVIDER`
  - `LEGAL_REFUND_POLICY`: the in-person cancellation/refund rules. The Terms also promise a full refund if *you* cancel the event; confirm or change that wording in `Pages/Public/Legal/Terms.vue`
  - Confirm the data-processing terms of Pusher, Agora, Tawk.to and Bunny Fonts (the Privacy Policy relies on them for transfers outside South Africa, POPIA s72)
  - Then set `LEGAL_REVIEWED=true`; the "Draft pending legal review" notice disappears once it's true **and** nothing is `[TO COMPLETE]`
- [ ] If the wording changes after launch, bump `LEGAL_TERMS_VERSION` / `LEGAL_PRIVACY_VERSION` and `LEGAL_EFFECTIVE_DATE`: everyone is asked to accept again on their next visit. Acceptances are recorded in `legal_acceptances` (version, time, IP)
- [ ] Optional: `SEMINAR_PRIVACY_NOTICE_URL` replaces the built-in Privacy Policy link with an external notice
- [ ] Consent wording reviewed and approved (`config/seminar.php` → `consent_wording`: networking, Lumko communication, follow-up, speaker contact, **partner contact**). If you change the wording, also bump its `version`: every consent record stores the exact wording and version the person agreed to, so earlier consents keep their original text. The partner sentence says the partner is responsible for copies it makes; confirm that with your legal adviser
- [ ] Privacy Policy sections 2–4 (speakers and partners, referrals, client records) reviewed, and `LEGAL_PRIVACY_VERSION` / `LEGAL_EFFECTIVE_DATE` set to `2026-10-07` (or later) in the production `.env`, so everyone accepts the updated policy
- [ ] Partner agreements: each partner accepts in writing that leads may be used only for following up on the event, and that withdrawals are honoured
- [ ] The Privacy Policy's list of service providers still matches reality (section 5 of `/privacy`)
- [ ] Organiser legal name (`SEMINAR_ORGANISER_LEGAL_NAME`) is correct

## 6. Real-time and video

- [ ] Pusher and Agora credentials set; `VITE_PUSHER_*` were present **when `npm run build` ran** and `public/build` was uploaded afterwards
- [ ] Both rehearsals completed and signed off (`docs/rehearsal-plan.md`), including venue Wi-Fi and cellular
- [ ] YouTube parallel stream tested and saved as the secondary provider
- [ ] Capacity checked against the Pusher plan (`docs/realtime-capacity.md`)

## 6b. Content-Security-Policy

- [ ] During the rehearsals the browser console showed **zero** CSP violations on Home, Register, Live, Dashboard and admin pages (add any Agora/other hosts that appear to `app/Http/Middleware/SecurityHeaders.php` first)
- [ ] Only then set `SEMINAR_CSP_ENFORCE=true`, `config:cache`, and repeat a quick pass of the same pages

## 7. Safety net

- [ ] Nightly database backup running and stored off the server; pre-event snapshot taken; **restore tested** onto a scratch database
- [ ] `storage/app/private` (proofs of payment) is in the backup
- [ ] Error pages checked as a real visitor: `/no-such-page` (404), `/admin` as an attendee (403); neither shows a stack trace or file path
- [ ] A named person has the runbook (`docs/livestream-runbook.md`) and the server/Pusher/Agora/mail logins for the day

## 8. Final gates (run on the release build)

- [ ] `php artisan test` — all green except the known Breeze leftovers (see the Stage G report)
- [ ] `./vendor/bin/pint --test` clean; `composer analyse` clean; `npm run build` clean
- [ ] `composer audit` and `npm audit --omit=dev` clean (**`composer audit` must be run from a machine with working access to Packagist**)
- [ ] `npm run test:e2e` green (needs PHP on the path and port 8791 free)
- [ ] Residual-risk list reviewed and accepted (see the Stage H report and `docs/security.md`)
