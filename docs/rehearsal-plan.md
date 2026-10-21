# Rehearsal plan

Two scripted dress rehearsals: **7 days before** (Friday 6 November 2026) and **1 day before** (Thursday 12 November). The first finds problems while there is time to fix them; the second confirms the fixes and the final configuration. Run both against the **real production server, real Pusher/Agora accounts, real mail**, not a development machine — most of what can go wrong (queue worker, cron, TLS, DNS, mail deliverability, venue Wi-Fi) only exists there.

Use throwaway accounts on a domain you control (e.g. `rehearsal+01@yourdomain`) so the emails really arrive. Delete or anonymise them afterwards.

## Cast

- **10 audience accounts**: 6 online, 4 in person (registered through the real `/attend` flow, not created by hand).
- 1 super admin, 1 event manager (created with `lumko:create-admin`, assigned with `lumko:assign-manager`).
- 1 host machine + 1 phone for scanning.

## Script

| # | Step | Pass when |
|---|---|---|
| 1 | Register all 10 accounts via `/attend` in a real browser, including one **on a phone** | Every account receives the confirmation email **within 2 minutes** (proves queue worker + mail). Clicking the link sets a password and lands on the dashboard. `SESSION_SECURE_COOKIE` works over HTTPS |
| 2 | Re-register one existing email | The response is identical to a new registration; the owner gets a "you already have an account" email; no duplicate is created |
| 3 | Attempt to register 4 times quickly with one email | The stepper is never blocked mid-way; only the final submit is rate-limited |
| 4 | In-person accounts upload a proof of payment (one JPG, one PDF, one wrong file type) | Wrong type is refused; the two valid ones appear for the admin. Proof opens **only** through the admin route; `/storage/...` never serves it |
| 5 | Admin approves one, rejects one with a note, leaves one pending | Attendees receive the matching emails; the approved one's **Your ticket** page (open it on the attendee's own phone) shows a QR image and, under it, the ticket code as text. Look at it — an automated check once passed while this page showed "[object Object]" instead of a QR |
| 6 | **Check-in with a phone**: open Check-in on a phone, scan the QR code from another screen. Then type the code manually (read it from the ticket page). Then scan it again | First scan checks in, the second says "already checked in". The pending-payment ticket is refused. Camera works over HTTPS |
| 7 | Attendee dashboard for online and in-person accounts | Countdown is correct in SAST; the "next step" callout matches each account's state |
| 8 | Networking: 4 accounts opt in; send/accept/decline/withdraw requests | Only opted-in people appear; notifications reach the bell |
| 9 | Admin sets Agora as the provider and presses **Open the player now** in the Control room (no need to move the start time). **Host** opens Broadcast, runs the pre-flight, starts | Pre-flight all ticked. Token issued. Test attendees see "Join live session" |
| 10 | **10 viewers** open Live (mix of phone/cellular and laptop/Wi-Fi) | Video appears for all. Record which networks fail |
| 10a | Host creates a stage link for one speaker; the speaker (on their own laptop and home network) opens it and joins the stage | Viewers see **both** presenters; the speaker's name labels their tile; host and speaker hear each other without echo (headphones) |
| 10b | Host, then the speaker, **Share screen**: slides, then a browser tab playing a video with "Share tab audio" | Viewers (laptop **and** phone) see the slides full-frame with the presenter's camera in the corner, and hear the video. Turning a camera off shows the presenter's name, not "Waiting for the host" |
| 10c | Withdraw the speaker's link while they're on stage | They drop off within 15 minutes (the speaker token lifetime); the old link then shows a 404 |
| 11 | Leave a viewer connected for **over 1 hour** if practical (token TTL is 3600 s) | Video continues past the expiry — proves token renewal. If not practical, set `AGORA_TOKEN_TTL=120` for the rehearsal only and watch it renew, then set it back |
| 12 | Viewers submit questions; producer approves, features, hides; viewers upvote | Pending questions are visible only to their author until approved; approved ones appear live |
| 13 | Producer opens a poll (results hidden), viewers vote, close it | One vote each; counts appear only when allowed |
| 14 | 10 viewers click reactions repeatedly | Counts update; no page slowdown. Watch the Pusher dashboard message count against `docs/realtime-capacity.md` |
| 15 | **Real-time off**: temporarily blank `PUSHER_APP_KEY` in the *build* (or block `*.pusher.com` in one browser) | Live page shows "Live updates paused — refreshing automatically" and still shows new questions within ~15 s |
| 16 | **Video fallback**: with Agora set as primary and YouTube as secondary, break Agora (wrong channel name in one browser) and click "Watch on YouTube" | The YouTube embed plays. Also confirm the parallel OBS → YouTube stream actually reaches YouTube |
| 17 | **Close the player** (if still open) and end live; add a recording URL; set the event Completed | Attendees keep the dashboard, resources and feedback form; thank-you emails arrive within ~10 minutes; the recording link shows |
| 18 | Open the browser console on Home, Register, Live, Dashboard and the admin pages | **Zero CSP violations** recorded. Note every violation host (Agora may need one added) — required before enforcing the CSP |
| 19 | Force an error: open `/no-such-page`, and visit `/admin` as an attendee | Branded 404/403 pages, no stack trace, no file paths |
| 20 | Stop the queue worker for 5 minutes, register an account, restart it | The email is delivered after the restart — confirms nothing is lost |
| 21 | `php artisan queue:failed` and the log | No failed jobs; no unexpected errors in `storage/logs` |
| 22 | Restore the latest backup to a scratch database | It restores and an admin can log in |

## Sign-off

Record for each rehearsal: date, who ran it, the venue network used, every step that failed, and what was changed. The 1-day-before rehearsal must be **all green** before `docs/launch-checklist.md` is signed off.
