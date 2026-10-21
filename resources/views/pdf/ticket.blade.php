{{--
    The digital ticket, A4 portrait (App\Services\TicketPdf). Rendered by
    dompdf: no flexbox, grid, font-stretch or rgba, so the layout is absolute
    millimetres plus tables, and the "white/60"-style colours on navy are
    pre-blended (white/60 #9ca0ac is the faintest text on navy, ink-400
    #626d8b the faintest on paper — DESIGN.md). Top half: the credential on a
    paper band, as on the web ticket (navy panel, perforation with notches,
    white stub with the QR). Bottom half: programme, venue, the day.
    Every value from people or events is escaped; only data URIs built in
    TicketPdf are printed raw.
--}}
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Ticket — {{ $event['name'] }}</title>
<style>
    @font-face { font-family: 'Archivo Display'; src: url('{!! $fonts['display'] !!}') format('truetype'); font-weight: normal; font-style: normal; }
    @font-face { font-family: 'Archivo Name'; src: url('{!! $fonts['name'] !!}') format('truetype'); font-weight: normal; font-style: normal; }
    @font-face { font-family: 'Poppins'; src: url('{!! $fonts['body'] !!}') format('truetype'); font-weight: normal; font-style: normal; }
    @font-face { font-family: 'Poppins'; src: url('{!! $fonts['body_semibold'] !!}') format('truetype'); font-weight: bold; font-style: normal; }
    @font-face { font-family: 'Poppins Medium'; src: url('{!! $fonts['body_medium'] !!}') format('truetype'); font-weight: normal; font-style: normal; }

    @page { size: A4 portrait; margin: 0; }
    * { margin: 0; padding: 0; }
    /* dompdf sets Poppins' tall line boxes from its own metrics: keep line-height tight. */
    body { font-family: 'Poppins', sans-serif; font-size: 8.5pt; line-height: 1.2; color: #071130; background: #ffffff; }
    table { border-collapse: collapse; }
    td { vertical-align: top; }

    .abs { position: absolute; }
    .display { font-family: 'Archivo Display', sans-serif; font-weight: normal; }
    .name { font-family: 'Archivo Name', sans-serif; font-weight: normal; }
    .medium { font-family: 'Poppins Medium', sans-serif; font-weight: normal; }
    .semibold { font-weight: bold; }
    .eyebrow { font-size: 6.5pt; letter-spacing: 1.1pt; text-transform: uppercase; font-weight: bold; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; }

    /* Paper band and running head */
    .band { left: 0; top: 0; width: 210mm; height: 138mm; background: #f5f6f8; }
    .running { left: 15mm; top: 8.5mm; width: 180mm; color: #626d8b; }

    /* The credential */
    .panel { left: 15mm; top: 17mm; width: 108mm; height: 87mm; padding: 9mm 10mm 9mm 10mm; background: #071130; color: #ffffff; }
    .on-stage-label { color: #9ca0ac; }
    .on-stage-soft { color: #a8acb7; }
    .stub { left: 143mm; top: 17mm; width: 40mm; height: 91mm; padding: 7mm 6mm 7mm 6mm; background: #ffffff; text-align: center; }
    .perforation { left: 142.8mm; top: 21mm; height: 97mm; border-left: 0.35mm dashed #c3c9d4; }
    .notch { width: 7mm; height: 7mm; border-radius: 3.5mm; background: #f5f6f8; }

    /* Lower half */
    .section-title { font-size: 13pt; line-height: 1.1; padding-top: 2.5mm; border-top: 0.45mm solid #071130; }
    .programme td { padding: 2mm 0 2mm 0; border-bottom: 0.2mm solid #dde1e8; }
    .programme.compact td { padding: 1.1mm 0 1.1mm 0; }
    .muted { color: #4b5878; }
    .faint { color: #626d8b; }
    .signal { color: #1a5ab3; }
</style>
</head>
<body>

{{-- Paper band with the running head --}}
<div class="abs band"></div>
<table class="abs running">
    <tr>
        <td class="eyebrow">Lumko Seminars &middot; Admission ticket</td>
        <td class="eyebrow" style="text-align: right;">Issued {{ $issued }}</td>
    </tr>
</table>

{{-- Navy main panel --}}
<div class="abs panel">
    <table style="width: 108mm;">
        <tr>
            <td style="width: 60mm; padding-top: 1mm;">
                <img src="{!! $logo !!}" alt="Lumko" style="height: 7mm; width: 25.8mm;">
            </td>
            <td style="text-align: right;">
                @if ($event['day'])
                    <div class="display" style="font-size: 34pt; line-height: 0.9;">{{ $event['day'] }}</div>
                    <div class="eyebrow on-stage-label" style="margin-top: 1.5mm;">{{ $event['month_year'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="display" style="margin-top: 7mm; width: 90mm; font-size: 30pt; line-height: 1;">{{ $event['name'] }}</div>

    <div style="margin-top: 7mm; border-top: 0.3mm solid #2c354f; padding-top: 4.5mm;">
        <div class="eyebrow on-stage-label">Attendee</div>
        {{-- Long names step down a size rather than wrap into the details below. --}}
        <div class="name" style="font-size: {{ mb_strlen($attendee['name']) > 30 ? '12pt' : '15pt' }}; line-height: 1.1; margin-top: 0.8mm;">{{ $attendee['name'] }}</div>
        @if ($attendee['organisation'])
            <div class="on-stage-soft" style="font-size: 7.5pt; margin-top: 0.4mm;">{{ $attendee['organisation'] }}</div>
        @endif
    </div>

    <table style="width: 108mm; margin-top: 4.5mm;">
        <tr>
            <td style="width: 35mm; padding-right: 3mm;">
                <div class="eyebrow on-stage-label">Admission</div>
                <div class="medium" style="margin-top: 0.8mm;">{{ $admission }}</div>
            </td>
            <td style="width: 23mm; padding-right: 3mm;">
                @if ($event['time'])
                    <div class="eyebrow on-stage-label">Time</div>
                    <div class="medium" style="margin-top: 0.8mm;">{{ $event['time'] }}</div>
                @endif
            </td>
            <td>
                @if ($event['venue_name'])
                    <div class="eyebrow on-stage-label">Venue</div>
                    <div class="medium" style="margin-top: 0.8mm;">{{ $event['venue_name'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- The series signature: the morning to scale, keyed to the programme below. --}}
    @if (count($ruler))
        <div class="abs" style="left: 10mm; bottom: 8mm; width: 108mm; height: 5mm;">
            @foreach ($ruler as $segment)
                <div class="abs" style="left: {{ $segment['left'] }}%; top: 0; width: {{ $segment['width'] }}%;">
                    <div style="margin-right: 0.7mm; border-top: 0.6mm solid #c1c4cb;"></div>
                    <div class="on-stage-label" style="font-size: 5.5pt; margin-top: 0.8mm;">{{ $segment['index'] }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- Perforation: tear line and two notches cut from the band colour --}}
<div class="abs perforation"></div>
<div class="abs notch" style="left: 139.3mm; top: 13.5mm;"></div>
<div class="abs notch" style="left: 139.3mm; top: 118.5mm;"></div>

{{-- Stub: what the door scans --}}
<div class="abs stub">
    <div class="eyebrow faint">Check-in</div>
    <img src="{!! $qr !!}" alt="Check-in QR code" style="width: 38mm; height: 38mm; margin-top: 2mm;">
    <div class="muted" style="font-size: 7pt; margin-top: 1mm;">Show this at the door.</div>

    <div style="margin-top: 3mm; border-top: 0.2mm solid #dde1e8; padding-top: 2.5mm;">
        <div class="muted" style="font-size: 6.5pt;">Won't scan? Give the door staff this code:</div>
        <div style="margin-top: 1.2mm;">
            @foreach ($code_lines as $line)
                <div class="mono" style="font-size: 8pt; line-height: 1.3;">{{ $line }}</div>
            @endforeach
        </div>
        <div class="faint" style="font-size: 6pt; margin-top: 0.8mm;">Letters are case-sensitive.</div>
    </div>

    <div class="abs" style="left: 6mm; bottom: 7mm; width: 40mm;">
        <span style="display: inline-block; width: 2mm; height: 2mm; border-radius: 1mm; background: #00d97e;"></span>
        <span class="semibold" style="font-size: 7pt;">&nbsp;Confirmed</span>
    </div>
</div>

{{-- Lower half: programme, venue, the day --}}
<div class="abs" style="left: 15mm; top: 150mm; width: 108mm;">
    <div class="display section-title">Programme</div>
    @if (count($programme))
        {{-- A long programme runs compact so it always ends above the footer. --}}
        @php($compact = count($programme) > 7)
        <table class="programme{{ $compact ? ' compact' : '' }}" style="width: 108mm; margin-top: 1.5mm;">
            @foreach (array_slice($programme, 0, 13) as $session)
                <tr>
                    <td class="faint" style="width: 8mm; font-size: 7.5pt;">{{ $session['index'] }}</td>
                    <td class="muted" style="width: 22mm; font-size: 7.5pt;">{{ $session['time'] }}</td>
                    <td>
                        <div class="medium" style="font-size: {{ $compact ? '7.5pt' : '8.5pt' }};">{{ $compact ? Str::limit($session['title'], 52) : $session['title'] }}</div>
                        @if ($session['speakers'])
                            <div class="signal" style="font-size: 7pt; margin-top: 0.5mm;">{{ $session['speakers'] }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
        @if (count($programme) > 13)
            <p class="muted" style="margin-top: 1.5mm; font-size: 7pt;">And {{ count($programme) - 13 }} more: the full programme is on your dashboard.</p>
        @endif
    @else
        <p class="muted" style="margin-top: 3mm;">The running order will be on your dashboard once it is published.</p>
    @endif
</div>

<div class="abs" style="left: 133mm; top: 150mm; width: 62mm;">
    @if ($event['venue_name'] || $event['venue_address'])
        <div class="display section-title">Venue</div>
        @if ($event['venue_name'])
            <div class="semibold" style="margin-top: 3mm;">{{ $event['venue_name'] }}</div>
        @endif
        @if ($event['venue_address'])
            <div class="muted">{{ $event['venue_address'] }}</div>
        @endif
    @endif
    @if ($event['long_date'])
        <div class="muted" style="margin-top: 1.5mm;">{{ $event['long_date'] }}@if ($event['time']), {{ $event['time'] }}@endif</div>
    @endif

    <div class="display section-title" style="margin-top: 9mm;">On the day</div>
    <table style="width: 62mm; margin-top: 2mm;">
        <tr><td style="padding: 1.2mm 0;">Bring this ticket, printed or on your phone.</td></tr>
        @if ($event['start_clock'])
            <tr><td style="padding: 1.2mm 0; border-top: 0.2mm solid #dde1e8;">We start at {{ $event['start_clock'] }}. Allow a few minutes to check in.</td></tr>
        @endif
        <tr><td style="padding: 1.2mm 0; border-top: 0.2mm solid #dde1e8;">Each ticket admits one person and is checked in once.</td></tr>
        @if ($organiser['contact_email'])
            <tr><td style="padding: 1.2mm 0; border-top: 0.2mm solid #dde1e8;">Questions? <span class="signal">{{ $organiser['contact_email'] }}</span></td></tr>
        @endif
    </table>
</div>

{{-- Footer --}}
<table class="abs faint" style="left: 15mm; top: 278mm; width: 180mm; border-top: 0.2mm solid #dde1e8; font-size: 6.5pt;">
    <tr>
        <td style="padding-top: 2.5mm;">
            {{ collect([$organiser['legal_name'], $organiser['registration_number'] ? 'Reg. '.$organiser['registration_number'] : null, $organiser['physical_address']])->filter()->implode(' · ') }}
        </td>
        <td style="padding-top: 2.5mm; text-align: right;">{{ $host }}</td>
    </tr>
</table>

</body>
</html>
