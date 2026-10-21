# Lumko Seminars — Design System: "Briefing on stage"

A Lumko seminar is presented as an executive briefing delivered on a lit stage.
Navy **stage** fields hold the moments of arrival; white **paper** holds reading
and doing. Typography carries the hierarchy; colour is used for meaning only.

This file describes what is built. Tokens live in `tailwind.config.js`,
shared primitives in `resources/css/app.css`, the direction contract in the
first comment of `resources/views/app.blade.php`.

## What it refuses

Centred hero, gradients, glass, blobs, three feature cards, pill buttons,
icon tiles, emoji, coloured side stripes on cards, decorative shadows.

## Colour

| Token | Value | Role |
|---|---|---|
| `ink-900` | #071130 | Text, stage fields, primary buttons |
| `ink-400` | #626d8b | Lightest ink allowed for text (5.1:1 on white, 4.7:1 on paper) |
| `ink-300` and lighter | | Rules, disabled states, never text |
| `signal-500` | #2C7BE5 | Rules, active marks, focus rings, the "current" state |
| `signal-700` | #1a5ab3 | Links and small blue text on paper (plain #2C7BE5 fails AA) |
| `verified-500` | #00D97E | Only confirmed, approved, checked-in. Dots, bars and fills; never text on paper |
| `paper` | #f5f6f8 | Recessed wells: programme band, ticket page, remittance header |
| `rule` / `rule-strong` | #dde1e8 / #c3c9d4 | Hairlines and control borders |

`brand` is an alias of `ink` so admin pages that still say `bg-brand` inherit it.
On stage, text is `white`, `white/75`, `white/70` or `white/60`. Nothing fainter than
`white/60` carries text.

Status colour appears as a **dot** (Badge), a **bar** (JourneyRail) or a **top
rule** (alerts, next-step panel), never as a tinted box or a left stripe.

## Type

- **Display:** Archivo Variable, self-hosted (`@fontsource-variable/archivo`, width axis).
  `.display` is 78% width on phones and 84% from `lg`, weight 800. Headings default to 86% / 760.
  The width axis is how headlines fit phones: they narrow before they shrink.
- **UI and body:** Poppins 400/500/600 from Bunny Fonts (Lumko brand face).
- **Figures:** `.tnum` (tabular, lining) wherever time, price, codes or countdowns appear.
- **Scale:** `text-display-xl` (hero), `text-display-md` (page and section titles),
  `text-display-sm` (sub-titles, names), `text-lede`, body 15px, `text-meta` 13px,
  `.eyebrow` 12px tracked caps (the metadata level the brief asked for).
- `.section-index` prints the briefing numbers: "01 / Brief".

## Grid, space, shape

- `.shell`: max 75rem, gutters 16 / 24 / 40px. 12-column grid from `lg`.
- Sections put their index in columns 1–2 and content from column 3, so a page reads as a numbered briefing.
- Section rhythm: `py-20 sm:py-24 lg:py-32`. Paper and stage bands alternate only where the content changes register.
- Corners: 2px on controls (`rounded-ctl`), 0 on fields, photos and panels.
- Hairline borders define structure. Shadows mean elevation, never decoration (owner decision 2026-09-24):
  `shadow-lift` for objects and panels that sit on the page (`Card`, the remittance slip, the ticket,
  the dashboard next-step panel); `shadow-float` for overlays (`Modal`, the notifications menu).
  Both are navy-tinted and defined in `tailwind.config.js`.

## Signature: the time ruler

`TimeRuler.vue` draws the programme to scale: each session's width is its
duration, with 10-minute ticks and index numbers that key into the list beside it.
Used full on the attendee dashboard and compact on the ticket. Not on the public landing
page: the programme is for registered attendees (the hero fallback shows only the ghost start time).
With `now-minutes` it marks the running session in signal blue. Every future seminar
gets its own ruler; that is the series identity.

## Components

| Component | Use |
|---|---|
| `Button` | `primary` ink, `inverse` white-on-stage, `secondary`, `outline`, `ghost`, `danger`; `arrow` adds a trailing →; `external` opens a new tab, `native` is a plain same-tab link (downloads); 44px min height |
| `Badge` | Status indicator: dot + label |
| `EventFacts` | Date, time, venue. `row` for heroes, `stack` for side columns |
| `JourneyRail` | Register › Attend › Pay › Verify › Ticket; stages from `credentialStages()` in `lib/eventFormat.js` |
| `StepIndicator` | Registration progress. `bar` on phones, `list` in the desktop side column |
| `CopyValue` | Remittance line with copy button and live-region feedback |
| `StagePhoto` | Photo on navy with a designed fallback when the file is missing |
| `SeriesBrief` | Last band on the home page (paper) for the Lumko Seminars series: lede, facts ledger, three pillars under signal rules (typeset columns, not cards), the sectors sentence and an `outline` follow CTA, which also serves as the post-event "stay in touch". Copy from `config('seminar.series')` |
| `TimeRuler` | See above |
| `Notice` | Full-page messages (confirmations, closed, errors) on the grid |
| `PortalPage` | Attendee page frame: running head + ruled content column |
| `AuthFrame` | Account pages: title left, form right |
| `FormField` + `TextInput` / `Checkbox` | Every form field on public, attendee and auth pages. The error sits directly after the control; `:announce="false"` where an error summary already announces |
| `EventImageField` | Admin: one landing-page photo slot with preview, alt text and remove |
| `.field`, `.field-label`, `.field-error`, `.choice` | Control primitives behind those components. `.choice` is a selectable row for radios and checkboxes |
| `.ledger` | Label/value rows separated by hairlines |

## Surfaces

- **Landing:** stage hero split 7/5 with venue photo bleeding right (phones: photo first).
  01 Brief (open lede), 02 Speakers (alternating portrait spreads), 03 Attend
  (comparison ledger on subgrid, not cards), 04 Venue (full-bleed photo, white plate),
  05 The series (`SeriesBrief` on a paper band, always last), close in the footer's navy field.
  Indexes are counted from the sections shown, so hiding Attend after the event leaves no gap.
  **No programme on the landing page** (owner decision 2026-09-28): it is for registered
  attendees and lives on the dashboard; the Brief ledger says so. With no published event,
  the "being prepared" hero is followed by the series band (indexed 01).
- **Account pages:** sign in, create account (standalone sign-up, re-enabled 2026-09-24), password reset and verification, all on `AuthFrame`.
- **Registration:** sticky side column (facts + step list) on desktop; segmented bar and
  a pinned Back/Next bar on phones.
- **Payment:** running head with status and journey; remittance slip; upload area wrapping the real input.
- **Ticket:** a credential. Navy main panel, perforation with notches, white stub with the QR.
  Horizontal from `md`, vertical on phones, prints on one page.
- **Portal:** navy header with underline tabs (scroll sideways on phones), stage band on the dashboard.

## Imagery

Each event's hero and venue photos are uploaded on the admin event form (stored on the public
disk under `events/`, with alt text). Speaker headshots come from the admin speaker upload. Photos sit on
navy with a light multiply grade. Missing photos fall back to ghost lettering (`.ghost-text`,
drawn from a data attribute so it stays out of the text and accessibility tree) and the ruler.

## Motion

Once, on arrival: `.rise` staggers the hero; ruler segments draw left to right when first seen.
150ms colour transitions on controls. Everything collapses under `prefers-reduced-motion`.
