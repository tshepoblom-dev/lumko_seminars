<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Behind a TLS-terminating reverse proxy or load balancer, Laravel only sees
| plain HTTP unless it trusts the proxy's X-Forwarded-* headers. Without
| this, generated and *signed* URLs (the email-verification and
| confirm-registration links) come out as http://, secure cookies misbehave,
| and the browser reports mixed content.
|
| TRUSTED_PROXIES accepts:  "*"  (trust any proxy — only safe when the app
| is reachable solely through your proxy), or a comma-separated list of proxy
| IPs/CIDRs, e.g. "10.0.0.5,10.0.1.0/24". Leave empty when the web server
| terminates TLS itself.
|
| Illuminate\Http\Middleware\TrustProxies reads this file natively, and it
| survives `php artisan config:cache` (env() in bootstrap/app.php would not).
|
*/

$proxies = env('TRUSTED_PROXIES');

return [
    'proxies' => match (true) {
        blank($proxies) => null,
        trim((string) $proxies) === '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', (string) $proxies)))),
    },
];
