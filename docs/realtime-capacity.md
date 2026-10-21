# Real-time capacity estimate (Pusher)

**This is an estimate from reading the code, not a measurement.** Confirm it in rehearsal step 14 by watching the Pusher dashboard, and check your plan's current connection and message limits on Pusher's pricing page — I cannot verify current plan limits from here.

## What is broadcast

Every audience-visible message goes to **one** private channel, `event.{id}`, and **every connected attendee receives every message**. So cost = (number of publishes) × (number of connected attendees).

| Message | When | Publishes | Notes |
|---|---|---|---|
| Session started / ended / changed, go-live, end-live | Producer action | ~1 per action (roughly 15 per show) | |
| Poll opened / closed | Producer or `polls:tick` | 2 per poll | |
| Question approved / hidden / featured / answered | Producer action | 1 per action | Pending questions are **not** broadcast to attendees — only to the staff channel |
| **Question upvote** | Every vote | **1 per vote** | No batching — each vote reaches everyone |
| **Reaction aggregate** | While reactions are being clicked | **At most 1 per 2 seconds** per event (`Cache::add` lock in `ReactionController`), carrying the current counts. Each viewer's page floats up the new emoji from the difference, so no extra messages | This is the dominant cost |
| **Chat message** | Every chat post | **1 per post** | No batching: each post reaches everyone. Capped at 6 posts per user per minute (`chat` limiter) |
| Chat message hidden | Staff action | 1 | |
| Poll votes (to attendees) | Only while a poll shows results live | **At most 1 per 2 seconds** per poll (`Cache::add` lock in `Public\PollController`) | Closing the poll sends the final counts |
| Poll results (to presenters) | Every vote, open and close | 1 per vote | `presenters.event.{id}`: staff and speakers only (a handful of subscribers) |
| Question submitted (to staff) | Each submission | 1 | Staff channel only (a handful of subscribers) |

Per-user throttles bound the worst case: 3 questions/min, 6 chat posts/min, 20 reactions/min, 30 upvotes/min, 10 poll responses/min.

**Chat (added 2026-09-28) is not in the worked example below.** At 100 viewers, every 100 chat posts add about 10,000 deliveries. A lively chat (a quarter of viewers posting 4 times each) adds about 10,000; a very busy one could add several times that. Add it to the realistic column before choosing a Pusher plan.

## Worked example: 100 attendees watching online, 80-minute show

| Source | Assumption | Publishes | Deliveries (× 100) |
|---|---|---|---|
| Producer actions | ~15 session/live + 4 polls × 2 + ~40 question actions | ~63 | ~6,300 |
| Upvotes | 40 % of attendees vote on ~3 questions | ~120 | ~12,000 |
| Reactions — realistic | Reactions active 25 % of the show = 20 min = 600 windows | 600 | 60,000 |
| **Total (realistic)** | | ~780 | **~78,000** |
| Reactions — worst case | Someone is clicking the whole 80 min = 2,400 windows | 2,400 | 240,000 |
| **Total (worst case)** | | ~2,580 | **~258,000** |

Reactions are roughly three-quarters of the traffic. Pusher may count each publish as well as each delivery; that adds only the publish count (hundreds), so it doesn't change the picture.

**Connections:** one per open Live page — about 100 attendees + staff. A second tab or a phone-and-laptop pair counts twice.

## Scaling: more than 100 registrations

More than 100 people are expected to register, mostly to watch online. **Registrations are not concurrent viewers** — plan for perhaps half to two-thirds of them live at once, and re-do this arithmetic with the real count. Deliveries grow faster than linearly because every upvote is delivered to everyone:

`deliveries ≈ N × (63 producer + 1.2 × N upvotes + reaction windows)`, with 600 reaction windows (realistic) or 2,400 (worst case).

| Concurrent viewers (N) | Realistic | Worst case |
|---|---|---|
| 100 | ~78,000 | ~258,000 |
| 150 | ~126,000 | ~396,000 |
| 200 | ~181,000 | ~541,000 |

What to check before the event, using the Pusher dashboard and pricing page (I can't verify current plan limits from here): the plan's **concurrent-connection cap** — at 150–200 viewers that is more likely to be the binding limit than daily messages — and its daily message allowance against the worst-case column. If either is tight, lengthen the reaction window (below) or upgrade for the day. Also expect registrations to arrive in bursts when the invitation goes out: the per-IP registration cap (`SEMINAR_REGISTRATION_IP_PER_MINUTE`, default 30) and the queue worker sending the confirmation emails are what those bursts exercise.

## If it is too much

1. **Lengthen the reaction window** — the 2-second lock in `app/Http/Controllers/Public/ReactionController.php` (`Cache::add(..., now()->addSeconds(2))`). 5 seconds cuts reaction traffic by 60 %; the counts are still exact, just refreshed less often.
2. Upgrade the Pusher plan for the event day (the cheapest fix).
3. Do nothing: the page already falls back to polling if Pusher is over quota or down. The fallback costs no Pusher messages but adds server load — about 100 attendees × 6 requests/min ≈ 10 requests/second, which a small server handles.

## Not modelled

Reconnect storms, the staff channel, the Pusher `/broadcasting/auth` calls (throttled to 30/min/user), and attendee counts far above ~100. Re-run the arithmetic with your real registration numbers before the event.
