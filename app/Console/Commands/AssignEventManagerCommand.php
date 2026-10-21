<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Stage H — event managers only see events they are assigned to
 * (Event::scopeVisibleTo), and nothing else in the app assigns them, so
 * without this an event_manager account can see no events at all.
 */
class AssignEventManagerCommand extends Command
{
    protected $signature = 'lumko:assign-manager {email} {event : Event id or slug} {--remove : Unassign instead of assign}';

    protected $description = 'Assign (or with --remove, unassign) an event manager to an event';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();

        if (! $user) {
            $this->error('No user with that email address.');

            return self::FAILURE;
        }

        if ($user->role !== UserRole::EventManager) {
            $this->error("That account's role is '{$user->role?->value}'. Only event_manager accounts are assigned to events (super admins already see every event). Create one with: php artisan lumko:create-admin {email} --role=event_manager");

            return self::FAILURE;
        }

        $reference = $this->argument('event');
        $event = is_numeric($reference)
            ? Event::find((int) $reference)
            : Event::where('slug', $reference)->first();

        if (! $event) {
            $this->error("No event with id or slug '{$reference}'.");

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $event->managers()->detach($user->id);
            Audit::record('event.manager_unassigned', subject: $event, eventId: $event->id, after: ['user_id' => $user->id]);
            $this->components->info("Removed {$user->email} from \"{$event->name}\".");

            return self::SUCCESS;
        }

        $event->managers()->syncWithoutDetaching([$user->id => ['role' => 'event_manager']]);
        Audit::record('event.manager_assigned', subject: $event, eventId: $event->id, after: ['user_id' => $user->id]);
        $this->components->info("Assigned {$user->email} to \"{$event->name}\".");

        return self::SUCCESS;
    }
}
