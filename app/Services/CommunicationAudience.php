<?php

namespace App\Services;

use App\Enums\CommunicationCategory;
use App\Models\Event;
use App\Models\User;
use App\Support\PlatformAudienceFilter;
use App\Support\RegistrantFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a communication reaches, as a users query. The composer's live count,
 * its "choose people" list and the actual send all come from here, so the
 * number an admin confirms is the number that goes out.
 *
 * - An event send reaches people with a `registered` registration for that
 *   event; a platform send ($event null) reaches every attendee account.
 * - `all` ignores filters, `filtered` applies them, `selected` is exactly
 *   the picked user ids (still limited to the audience above).
 * - Marketing only ever reaches people whose Lumko marketing consent is
 *   granted; each send re-checks this again per person at send time.
 */
class CommunicationAudience
{
    public const MODES = ['all', 'filtered', 'selected'];

    public function sanitizeFilters(?Event $event, array $input): array
    {
        return $event ? RegistrantFilter::sanitize($input, $event) : PlatformAudienceFilter::sanitize($input);
    }

    /**
     * @param  array<int>  $userIds  only used for the `selected` mode
     */
    public function query(?Event $event, CommunicationCategory $category, string $mode, array $filters, array $userIds = []): Builder
    {
        $applied = $mode === 'filtered' ? $filters : [];

        $users = $this->base($event, $applied);

        if ($mode === 'selected') {
            $users->whereIn('users.id', array_map('intval', $userIds));
        }

        if ($category === CommunicationCategory::Marketing) {
            RegistrantFilter::whereMarketingConsent($users);
        }

        return $users;
    }

    /** The "choose people" list: the audience narrowed by the filters. */
    public function candidates(?Event $event, CommunicationCategory $category, array $filters): Builder
    {
        return $this->query($event, $category, 'filtered', $filters)
            ->select(['users.id', 'users.name', 'users.email', 'users.organisation'])
            ->orderBy('users.name');
    }

    private function base(?Event $event, array $filters): Builder
    {
        if (! $event) {
            return PlatformAudienceFilter::apply(User::query(), $filters);
        }

        return User::query()->whereHas('registrations', function ($registrations) use ($event, $filters) {
            $registrations->where('event_id', $event->id)->where('status', 'registered');
            RegistrantFilter::apply($registrations, $filters, $event);
        });
    }
}
