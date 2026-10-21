# Product context — Lumko Seminars

**What it is.** seminar.lumko.cloud is Lumko Business Technology Partners' reusable
platform for business seminars, executive breakfasts, professional forums and
knowledge-sharing events. The first event is *The Power of Data* (13 November 2026,
08:00–09:20, St Eve's Spa & Lodge, Bloemfontein), online and in person.

**The series.** *Lumko Seminars* is a series of hybrid breakfast conversations that brings
together industry leaders, academics, executives and professionals. It travels to different
cities over the coming year and rests on three pillars: technology, business and society.
Fields include LegalTech, EduTech, MiningTech and FinTech; these are examples, not the full
scope. Each seminar is an open platform for practical insight, expert perspectives and
meaningful discussion. The series is
part of Lumko's outreach, but it is presented as an **expert advisory and learning
platform**, never as a marketing platform. The home page explains it (copy in
`config/seminar.php` → `series`), so visitors follow the series, not just one event.

**Who uses it.** Industries, organisations, government entities and professionals:
senior, invited, busy, reading on a phone between meetings or at a desk. The first
event draws mainly on the Free State. Organisers and door staff use the admin area.

**What the public site must do.** Work as the digital invitation: say what, when and
where at a glance, show the speakers, and move people through
Register › Attend › Pay (EFT, in person only) › Verify › Ticket. The programme is for
registered attendees only and is shown on their dashboard, not on the public page
(owner decision 2026-09-28). The page closes with the Lumko Seminars series.

**Brand commitments (owner decisions, 2026-09-24).**
- Palette: navy #071130, blue #2C7BE5, green #00D97E. Colour used with restraint.
- Poppins for UI and body; a characterful display face for headlines (Archivo Variable).
- Direction: executive briefing grid and typography, combined with a navy "night forum" stage and real photography.
- Venue and speaker photography will be supplied.
- Light interface overall; the public site and attendee portal feel like one product.

**Must never feel like** an Eventbrite clone, a generic SaaS or AI startup template,
a government portal, or a sales funnel.

**Constraints.** Laravel 12 + Inertia v2 + Vue 3 + Tailwind 3. WCAG 2.1 AA (the e2e suite
runs axe on every page). Mobile-first. No invented commercial claims.
