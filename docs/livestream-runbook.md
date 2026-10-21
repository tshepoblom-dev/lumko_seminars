# Livestream & real-time runbook

Covers setting up Pusher (live Q&A, polls, reactions) and Agora (interactive video), running the show on the day, the fallback if either fails, and post-event steps. Everything here was written from the code; **nothing has been exercised against real Pusher or Agora accounts** (credentials are still outstanding), so treat the setup sections as a checklist to confirm during the rehearsal (`docs/rehearsal-plan.md`), not as proven.

## How the pieces fit

| Feature | Transport | If it fails |
|---|---|---|
| Video for attendees | Agora (interactive) **or** an embedded YouTube/Facebook stream | Switch to the secondary provider (below) |
| Live Q&A, polls, reactions, "session changed" | Pusher (private channel `event.{id}`) | The page shows "Live updates paused — refreshing automatically" and polls the server every 10 s (15 s in a background tab). Nothing is lost; it is just slower |
| Moderation inbox for staff | Pusher (`admin.event.{id}`) | Staff refresh the Questions page |

The video and the real-time layer fail independently.

## 1. Pusher setup

1. Create a Pusher Channels app at pusher.com. **There is no Africa cluster.** Pick the cluster with the lowest latency from the venue and from attendees (`eu` — Ireland — is the usual choice for South Africa, but measure; don't assume). The cluster cannot be changed later.
2. Copy `app_id`, `key`, `secret`, `cluster` into `.env`: `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER`, and set `BROADCAST_CONNECTION=pusher`.
3. **`VITE_PUSHER_APP_KEY` and `VITE_PUSHER_APP_CLUSTER` are baked into the JavaScript at build time.** Set them in the environment where you run `npm run build`, and rebuild + re-upload `public/build` whenever they change. Changing only the server `.env` is not enough.
4. `php artisan config:cache && php artisan queue:restart`.
5. Check limits: the free plan caps concurrent connections and daily messages. See `docs/realtime-capacity.md` for the expected volume, and confirm the plan's current limits on Pusher's pricing page.

Key rotation: rotate the app secret in the Pusher dashboard → update `PUSHER_APP_SECRET` → `config:cache` and `queue:restart`. Rotating the *key* requires a rebuild (step 3).

Channel authorisation is done by the app at `/broadcasting/auth` (verified email + registered for the event; staff must be assigned to it). Anyone else is refused.

## 2. Agora setup

1. Create a project in the Agora console with **App certificate enabled**. Copy the App ID and primary certificate into `AGORA_APP_ID` and `AGORA_APP_CERTIFICATE`. Both are 32 hex characters; if either is mistyped, every token request fails with "Agora is misconfigured" (the token library itself would silently return an empty token).
2. `AGORA_TOKEN_TTL` (default 3600 s). The player renews tokens automatically before they expire, including for the host console.
3. Uids (`app/Support/AgoraStage.php`):

   | Who | uid |
   |---|---|
   | Staff host camera | their user id (must be below 100,000) |
   | Remote speaker camera | 100,000 + speaker id |
   | Anyone's screen share | their camera uid + 500,000,000 |
   | Audience | random, 1,000,000,000 or above, new per join |

   The player uses these ranges to put a screen share full-frame and cameras in the corner. Two tabs never collide, and a user id is never exposed to other viewers.
4. Audience can join once the event is live, within `live_lead_minutes` (default 15) of the start, **or** whenever staff press **Open the player now** in the Control room (for rehearsals and tests before the day; it doesn't make the event live or send the "Starting now" email). Close it again afterwards. **End live** also closes it. Staff can always join.
5. Network: browsers need outbound access to Agora over HTTPS/WSS and UDP media. Use Agora's current "firewall requirements" page for the exact domains and ports — **I have not verified them**. The site's Content-Security-Policy allows `*.agora.io` and `*.agoraio.cn`; if the rehearsal logs CSP violations for another Agora host (e.g. `sd-rtn.com`), add it to `app/Http/Middleware/SecurityHeaders.php` before enforcing the CSP.
6. **Test on cellular and on the venue's Wi-Fi.** If video doesn't work on the venue network, that is the signal to use the fallback.

## 2a. Remote speakers and screen sharing

**Screen sharing.** On the Broadcast console (host) and the speaker stage, **Share screen (slides)** publishes the screen *alongside* the camera: attendees see the slides full-frame with the presenter in a corner tile. Sharing can start before going on air; it goes out as soon as they do. To play a video with sound from the slides, share a **browser tab** and tick "Share tab audio" (Chrome/Edge). Whole-screen audio isn't available in every browser.

**Remote speakers** present from their own computer, with no account:

1. Link the speaker to the event (Plan → Speakers).
2. Broadcast console → **Remote speakers** → **Create link**. Copy the link straight away and send it to that speaker only. It is shown **once**; only a hash is stored. **New link** replaces it, and the old one stops working.
3. The speaker opens it in Chrome or Edge, checks their camera and mic, and presses **Join the stage**. **Attendees see and hear them from that moment.** Speakers hear each other and the host, so ask for headphones.
4. The console shows who is on stage while you are on air.
5. **Withdraw** kills the link. A speaker already on stage drops off within **15 minutes**, when their short-lived token can't be renewed. Agora has no in-browser way to remove someone sooner. If it's urgent, rename the event's channel (Event → Livestream) and have everyone else rejoin.

Links work until 2 hours after the event ends, only while the event streams through Agora, and they're rate-limited and `noindex`.

**Attendee view.** Control room and Broadcast console → **Open attendee view** (also "Attendee view" in the sidebar) opens the real `/live` page for *this* event in a new tab. Anything sent from it (questions, reactions, votes) is real.

## 3. Event settings (Admin → Events → Edit → Livestream)

| Field | Use |
|---|---|
| Provider | `agora` (needs a channel name), `youtube` or `facebook` (needs an https URL on the right host) |
| Secondary provider + URL | The fallback shown as "Having trouble? Watch on YouTube". Must differ from the primary |
| Live lead minutes | How early attendees can open the player |
| Recording URL | Fill in after the event (post-event step 3) |

## 4. Run of show — Friday 13 November 2026 (times SAST, from the seeded programme)

Roles: **Host** (operates the Broadcast console), **Producer** (Control room + moderation), **YouTube operator** (OBS). One person can hold two roles but not all three. Remote speakers join through their stage links (section 2a).

| When | Who | Action |
|---|---|---|
| T–60 min | Host | Open **Broadcast** in the admin nav on the *actual* broadcast machine and network. Pre-flight checklist must be all green: HTTPS, microphone detected, camera detected, and "Broadcast token" all ticked. Pick the camera and mic; check the level meter |
| T–60 min | YouTube operator | Start OBS → YouTube as a **parallel stream** (unlisted/public as agreed). Confirm the YouTube URL is saved as the *secondary provider* |
| T–45 min | Remote speakers | Open their stage links, check camera/mic, **Join the stage** briefly with the host for a sound check, then leave until their slot (attendees can't see the player yet) |
| T–30 min | Producer | Open the site as an attendee test account in another browser: dashboard shows the countdown, **Live** page loads, the "Live updates paused" note is *absent* (Pusher connected). **Open attendee view** from the Control room shows the same player for staff |
| 07:45 | — | Live-lead window opens: attendees can enter the player and see "Waiting for the host" |
| 07:55 | Host | **Start broadcast** |
| 07:58 | Producer | Control room → **Go live** (attendees now see LIVE) |
| 08:00 | Producer | Control room: press **Start** on the *Welcome & opening* row (runs until 08:05) |
| 08:05 | Producer | **Start** on *Data in municipalities & government* — Kgotla Paledi (until 08:30) |
| 08:30 | Producer | **Start** on *AI, data science & data sovereignty* — Dr Herkulaas Combrink (until 08:55) |
| throughout | Producer | Questions page: approve / feature / hide; questions stay hidden from other attendees until approved |
| 08:55 | Producer | **Start** on *Joint discussion / selected audience questions* (until 09:07). Feature the questions the panel will take; **Open** the prepared poll if using one |
| 09:07 | Producer | **Start** on *Closing remarks & thank you* (until 09:10). Close any open poll |
| 09:10 | Producer | **Start** on *Networking & departures* (until 09:20) |
| 09:20 | Producer | Control room → **End live**. Host → **Stop broadcast**. YouTube operator ends the YouTube stream |

Starting a session automatically ends the previous one. (**Show** on a row only marks it as the session attendees see as "Now:" without starting it.) After 09:20 the site switches itself to its post-event state.

## 5. If something fails

| Symptom | Action |
|---|---|
| Agora video fails for everyone | Event → Livestream: nothing to change if the secondary is set — attendees click **"Having trouble? Watch on YouTube"** (also offered automatically on a join error). Post the YouTube link as an **announcement** for anyone who misses it |
| Agora fails for some | Same button; ask them to try cellular |
| Pusher down / keys wrong | Nothing to do — pages fall back to polling. Tell the room questions may take ~10 s to appear |
| Host machine dies | Second staff member opens Broadcast on another machine and starts; the audience reconnects on its own. The YouTube parallel stream is the safety net |
| Both fail | Post the YouTube URL by announcement + email ("Send email" on the announcement) |
| Question spam | Hide it; attendees are already limited to 3 questions/minute |
| Remote speaker can't be heard or seen | They check the Checks list on their stage page; try Chrome/Edge, another network, or re-open the link. Last resort: they join the host's machine by phone/call audio |
| A stage link leaked | Broadcast console → **Withdraw** (or **New link** and resend). See the 15-minute note in 2a |

## 6. After the event

1. Control room: **End live**, Host: **Stop broadcast** (if not already done).
2. Wait for the platform's post-event state (after 09:20).
3. Admin → Events → Edit → Livestream: paste the **recording URL** (YouTube recording works). The home page, `/live` and the dashboard then link it.
4. Admin → Events → Edit: set **Status = Completed**. The event **stays visible** to attendees (recording, resources, feedback form) for `SEMINAR_POST_EVENT_DAYS`; completing it simply ends live operations and keeps it out of registration. (Do **not** use Archive for this — archiving hides the event from attendees.)
5. Thank-you + feedback emails go out automatically within ~10 minutes of the end time (`postevent:send`, needs the queue worker running). Confirm the worker log afterwards.
6. Review feedback (Admin → Feedback) and export/act on follow-up requests. Contact details appear only for people who asked to be contacted, and each view is audited.
