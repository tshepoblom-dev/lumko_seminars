<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * T-LINK-1: the front end must never reference a route name that does not
 * exist. Ziggy throws in the browser for unknown names, which blanks the page
 * (this is exactly what happened with the leftover Breeze `dashboard` and
 * `profile.edit` routes).
 */
class RouteNamesExistTest extends TestCase
{
    public function test_every_route_name_used_in_the_frontend_exists(): void
    {
        $names = array_keys(app('router')->getRoutes()->getRoutesByName());
        $missing = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            if (! in_array($file->getExtension(), ['vue', 'js'], true)) {
                continue;
            }

            $source = $file->getContents();

            // route('name', ...), route("name"), route(`prefix.${dynamic}`)
            // (and the event-workspace helper eventRoute('name', ...))
            preg_match_all('/\b(?:route|eventRoute)\(\s*([\'"`])([^\'"`]+)\1/', $source, $calls);
            // Navigation data such as { route: 'admin.payments.index' }
            preg_match_all('/\broute:\s*([\'"])([a-z][\w.\-]*)\1/', $source, $data);

            foreach (array_merge($calls[2], $data[2]) as $name) {
                if (! $this->routeNameExists($name, $names)) {
                    $missing[] = $name.'  <-  '.$file->getRelativePathname();
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "Undefined route names referenced in JS:\n".implode("\n", $missing));
    }

    private function routeNameExists(string $name, array $names): bool
    {
        if (! str_contains($name, '${')) {
            return in_array($name, $names, true);
        }

        // Template literal: treat each ${...} as "any single name segment".
        $pattern = preg_replace('/\$\{[^}]*\}/', '__X__', $name);
        $pattern = str_replace('__X__', '[\w\-]+', preg_quote($pattern, '/'));

        foreach ($names as $existing) {
            if (preg_match('/^'.$pattern.'$/', $existing)) {
                return true;
            }
        }

        return false;
    }
}
