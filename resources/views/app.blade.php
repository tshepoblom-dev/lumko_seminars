<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#071130">
        <link rel="preload" as="image" href="/images/logo-light.svg">
        <link rel="preload" as="image" href="/images/logo.svg">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts: Poppins (UI, Lumko brand) from Bunny; Archivo Variable (display) is self-hosted via app.css -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=poppins:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        <!--
        THESIS: A Lumko seminar is presented as an executive briefing delivered on a lit stage. Refuses the centred-hero / gradient / three-card event template.
        OWN-WORLD: Navy #071130 stage fields for arrival moments (hero, ticket, live, portal header, footer); white paper for reading and doing. Archivo Variable condensed display, Poppins UI, tabular figures. Hairline rules, 2px controls, no shadows. Blue #2C7BE5 marks action and rules; green #00D97E only means verified.
        STORY: The visitor learns what, when and where in one glance, sees the morning as a timed programme, meets the speakers, picks online or in person, and moves Register > Attend > Pay > Verify > Ticket.
        FIRST VIEWPORT: Navy stage split 7/5. Eyebrow, condensed title at display scale, subtitle, date/time/venue set as a fact line, primary "Register to attend". Venue photograph right (navy ruler fallback). Phone: photo first, title narrows by width axis.
        FORM: Executive Briefing x Night Forum hybrid, owner-chosen. Signature: the proportional programme time ruler.
        FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
        -->
        @inertia
    </body>
</html>
