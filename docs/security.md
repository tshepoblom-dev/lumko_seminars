# Security & privacy notes

A working threat-model summary of what the platform protects, what stands in the way, and what it does **not** cover. It describes the code as it is; it is not a penetration test and no external review has been done.

## What is worth protecting

| Asset | Why |
|---|---|
| Attendee personal data (name, email, mobile, organisation, answers) | POPIA personal information |
| Proofs of payment | Bank details / identity documents |
| Consent records | Legal evidence of what each person agreed to, and when |
| Staff accounts and the audit log | Control of everything above |
| The live stream and Q&A | Availability and integrity during a one-off event |

## Controls in place

**Access control**
- Roles: `attendee`, `event_manager`, `super_admin`. `role` is not mass-assignable; it changes only through `RoleService`, which audits it.
- Every admin controller authorises through policies scoped to the event a manager is assigned to (`Event::visibleTo`). An unassigned manager gets 403, and cross-event IDs return 404. (A missing scope on the Engagement page was found and fixed during Stage D.)
- Attendee features sit behind `auth` + `verified` + "registered for this event" (`EnsureEventAttendee`).
- Real-time channels are authorised server-side; staff must be assigned to the event.
- No default credentials in production: the seeder creates an admin only in `local`/`testing`; production admins come from `lumko:create-admin` with a strong password.

**Registration & abuse**
- Submitting the registration form never logs anyone in; only the signed, expiring confirmation link (which also sets the password) does. The HTTP response is identical whether or not an account already exists (no account enumeration).
- Submit limited to 3/hour per email (fixed) and, per IP, `SEMINAR_REGISTRATION_IP_PER_MINUTE` (default 30; the original spec said 5, raised because 100+ registrants are expected and many share one public IP, e.g. a municipal office). Lower it if you see abuse. Per-step validation has its own limiter (60/min per IP) — a shared one locked out honest users, fixed in Stage G.
- Per-user limits on questions, upvotes, poll votes, reactions, networking requests and feedback.

**Data handling**
- Ticket codes are encrypted at rest and looked up through a keyed hash.
- Proofs of payment and resources live on a private disk, are served only through authorised routes with `nosniff`, a restrictive CSP and `no-store`, and are deleted after `SEMINAR_POP_RETENTION_DAYS`.
- Uploads are allow-listed by type and size; speaker photos are not public until approved.
- Consent is an append-only ledger with the exact wording and version shown; withdrawing takes effect immediately. A guard (`GuardsMarketingConsent`) blocks any marketing-category notification to someone who has not opted in; today every notification the app sends is event/transactional, so nothing currently exercises it.
- Contact details in feedback are shown to staff only for people who asked to be contacted, and each view is audited. PII list/profile views, payment-proof views, check-ins, role changes and moderation are written to an insert-only audit log.
- Attendees can export all their data and delete (anonymise) their account themselves.

**Transport & browser**
- HTTPS is required in production; HSTS, `nosniff`, referrer policy, frame protection and a permissions policy are sent on every response. The CSP is in **report-only** mode until the rehearsal shows no violations (`SEMINAR_CSP_ENFORCE`).
- Session cookies are `Secure` and `SameSite=Lax` in production. CSRF protection is on for every form and AJAX call.
- Errors never show stack traces when `APP_DEBUG=false`.

## Known gaps and residual risks

1. **CSP is not enforcing yet**, so it currently protects nothing. Enforce it only after the rehearsal (see the launch checklist).
2. **Not independently reviewed.** Static analysis (Larastan level 5, with a 290-finding baseline of mostly false positives), automated tests and axe scans were run; there has been no penetration test or dependency review beyond `npm audit`. `composer audit` and `npm audit` report no known advisories at the time of writing.
3. **Dev database credential.** The development database (the one `.env` points at on the dev machine) contains the seeded `admin@lumko.cloud` account with the well-known password. It must never be copied to production or reachable from outside.
4. **Two-factor authentication does not exist** for staff accounts. Staff passwords are the only barrier to the attendee database; use long unique passwords.
5. **Login rate limiting** is Laravel's default per email+IP; there is no account lockout or breach-password check.
6. **Backups are the operator's job** and are not encrypted by the app; they contain personal data and must be protected accordingly.
7. **Announcement publishing is at-most-once** if the process is killed mid-run (some recipients could miss the in-app notification).
8. **The privacy notice is not in the repository.** It is a legal document the organiser must supply; the app links to it through `SEMINAR_PRIVACY_NOTICE_URL` and shows no link until it is set.
9. **Third parties see data**: Pusher receives real-time payloads (question text, poll counts), Agora receives the video, the mail provider receives every email, YouTube/Facebook see any stream sent to them. The privacy notice should say so.
10. **Legacy Breeze files** (profile page, old register page, default dashboard) are still in the tree, unreachable, pending the owner's manual review and deletion.
