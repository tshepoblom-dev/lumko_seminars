<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Stage G follow-up — lets the Playwright suite look at the post-event pages
 * (recording, open feedback form, "registration closed") in a real ended
 * state. Testing environment only: it rewrites the event's dates.
 */
class E2eEndEventCommand extends Command
{
    protected $signature = 'e2e:end-event';

    protected $description = 'Move the seeded event to "ended yesterday" with a recording link (testing environment only)';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('Refusing to run outside the testing environment.');

            return self::FAILURE;
        }

        Event::where('slug', 'the-power-of-data')->firstOrFail()->update([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'is_live' => false,
            'recording_url' => 'https://example.com/recording',
        ]);

        $this->components->info('Seeded event is now ended.');

        return self::SUCCESS;
    }
}
