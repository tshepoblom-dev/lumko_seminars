// Presentation helpers shared by the public, registration and attendee pages.
// Event times are always shown in the event's own timezone, whatever the
// visitor's device is set to.
const TZ = 'Africa/Johannesburg';
const LOCALE = 'en-ZA';

function fmt(iso, options) {
    if (!iso) return '';
    try {
        return new Intl.DateTimeFormat(LOCALE, { timeZone: TZ, ...options }).format(new Date(iso));
    } catch {
        return '';
    }
}

/** "Friday 13 November 2026" */
export function longDate(iso) {
    const weekday = fmt(iso, { weekday: 'long' });
    const rest = fmt(iso, { day: 'numeric', month: 'long', year: 'numeric' });
    return [weekday, rest].filter(Boolean).join(' ');
}

/** "13 Nov 2026" */
export function shortDate(iso) {
    return fmt(iso, { day: 'numeric', month: 'short', year: 'numeric' });
}

/** { day: "13", month: "Nov", weekday: "Fri", year: "2026" } for typographic date blocks. */
export function dateParts(iso) {
    return {
        day: fmt(iso, { day: '2-digit' }),
        month: fmt(iso, { month: 'short' }),
        weekday: fmt(iso, { weekday: 'short' }),
        year: fmt(iso, { year: 'numeric' }),
    };
}

/** "08:00" */
export function clock(iso) {
    return fmt(iso, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
}

/** "08:00–09:20" */
export function timeRange(startIso, endIso) {
    const a = clock(startIso);
    const b = clock(endIso);
    return b ? `${a}–${b}` : a;
}

/** Minutes since midnight for an "HH:MM" string. */
export function minutes(hhmm) {
    if (!hhmm) return null;
    const [h, m] = String(hhmm).split(':').map(Number);
    return Number.isFinite(h) && Number.isFinite(m) ? h * 60 + m : null;
}

/** "25 min" / "1 h 20 min" */
export function duration(startHHMM, endHHMM) {
    const a = minutes(startHHMM);
    const b = minutes(endHHMM);
    if (a === null || b === null || b <= a) return '';
    const total = b - a;
    const h = Math.floor(total / 60);
    const m = total % 60;
    if (!h) return `${m} min`;
    return m ? `${h} h ${m} min` : `${h} h`;
}

/** Whole-rand price: "R150". Free is spelled out. */
export function rand(amount) {
    const n = Number(amount);
    if (!Number.isFinite(n) || n <= 0) return 'Free';
    return `R${n.toFixed(0)}`;
}

export function mapsUrl(venueName, venueAddress) {
    const q = [venueName, venueAddress].filter(Boolean).join(', ');
    return q ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}` : null;
}

/**
 * The attendee's credential journey, Register > Attend > Pay > Verify > Ticket,
 * derived only from facts the server already sends. Each stage is
 * { key, label, state: 'done' | 'current' | 'todo' }.
 */
export function credentialStages({ inPerson = true, paymentStatus = null, proofUploaded = false, ticketConfirmed = false, complimentary = false } = {}) {
    if (!inPerson) {
        return [
            { key: 'register', label: 'Register', state: 'done' },
            { key: 'attend', label: 'Online, free', state: 'done' },
            { key: 'join', label: 'Join live', state: 'current' },
        ];
    }

    // An invitation code: no payment to make or verify.
    if (complimentary) {
        return [
            { key: 'register', label: 'Register', state: 'done' },
            // Short: rail labels truncate beyond about "Online, free".
            { key: 'attend', label: 'As our guest', state: 'done' },
            { key: 'ticket', label: 'Ticket', state: ticketConfirmed ? 'done' : 'current' },
        ];
    }

    const approved = paymentStatus === 'approved';
    const reviewing = paymentStatus === 'under_review';
    const paid = approved || reviewing || (proofUploaded && paymentStatus !== 'rejected');

    return [
        { key: 'register', label: 'Register', state: 'done' },
        { key: 'attend', label: 'In person', state: 'done' },
        { key: 'pay', label: 'Pay', state: paid ? 'done' : 'current' },
        { key: 'verify', label: 'Verify', state: approved ? 'done' : paid ? 'current' : 'todo' },
        { key: 'ticket', label: 'Ticket', state: ticketConfirmed ? 'done' : approved ? 'current' : 'todo' },
    ];
}

/**
 * "2026-11-13T08:00" in the event timezone, for <input type="datetime-local">.
 * The server serialises dates in UTC; slicing that string would show (and then
 * save) the time two hours early.
 */
export function toLocalInput(iso) {
    if (!iso) return '';
    try {
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat('en-CA', {
                timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
            }).formatToParts(new Date(iso)).map((p) => [p.type, p.value]),
        );
        return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
    } catch {
        return '';
    }
}
