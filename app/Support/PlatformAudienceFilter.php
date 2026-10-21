<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Event;
use App\Services\RegistrationForm;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters for a platform-wide (super admin) send: every attendee account
 * on the platform, whichever events they registered for. A registration
 * answer (industry, size, interest) matches if it appears on any of the
 * person's registrations.
 */
class PlatformAudienceFilter
{
    public const KEYS = ['search', 'event_id', 'industry', 'organisation_size', 'interest'];

    public static function sanitize(array $input): array
    {
        $filters = [];

        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? null;

            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $valid = match ($key) {
                'search' => is_string($value) && mb_strlen(trim($value)) <= 100,
                'event_id' => ctype_digit((string) $value) && Event::whereKey((int) $value)->exists(),
                'industry' => in_array($value, config('seminar.industries'), true),
                'organisation_size' => in_array($value, config('seminar.organisation_sizes'), true),
                // Any interest an event offers (each event edits its own list).
                'interest' => in_array($value, RegistrationForm::allInterestLabels(), true),
            };

            if ($valid) {
                $filters[$key] = match ($key) {
                    'search' => trim($value),
                    'event_id' => (int) $value,
                    default => $value,
                };
            }
        }

        return $filters;
    }

    /** $users is a users query; staff accounts are never part of the audience. */
    public static function apply(Builder $users, array $filters): Builder
    {
        $users->where('role', UserRole::Attendee->value);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $users->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('organisation', 'like', "%{$search}%"));
        }

        $registrationFilters = array_intersect_key($filters, array_flip(['event_id', 'industry', 'organisation_size', 'interest']));

        if ($registrationFilters) {
            $users->whereHas('registrations', function ($q) use ($registrationFilters) {
                $q->where('status', 'registered');

                if ($eventId = $registrationFilters['event_id'] ?? null) {
                    $q->where('event_id', $eventId);
                }
                if ($industry = $registrationFilters['industry'] ?? null) {
                    $q->where('industry', $industry);
                }
                if ($size = $registrationFilters['organisation_size'] ?? null) {
                    $q->where('organisation_size', $size);
                }
                if ($interest = $registrationFilters['interest'] ?? null) {
                    $q->whereJsonContains('interests', $interest);
                }
            });
        }

        return $users;
    }
}
