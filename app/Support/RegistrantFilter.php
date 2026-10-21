<?php

namespace App\Support;

use App\Enums\ConsentType;
use App\Models\ConsentRecord;
use App\Models\Event;
use App\Services\RegistrationForm;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters over one event's registrations, shared by the attendee list,
 * its CSV export and the communications composer, so "the people on screen",
 * "the people in the download" and "the people this email goes to" can
 * never drift apart.
 *
 * apply() takes an EventRegistration query already scoped to the event.
 */
class RegistrantFilter
{
    /** `answers` is a map: question id => option id (or yes/no). */
    public const KEYS = [
        'search', 'mode', 'payment_status', 'checked_in_only', 'networking_opt_in', 'lumko_opt_in',
        'wants_follow_up', 'industry', 'organisation_size', 'interest', 'account_set_up', 'answers',
    ];

    private const PAYMENT_STATUSES = ['pending', 'under_review', 'approved', 'rejected', 'cancelled'];

    /**
     * Only known keys with usable values: blanks are dropped and a value
     * outside its option list (an edited URL) is ignored, not an error.
     * Interests and question answers are checked against the event's own
     * registration questions (RegistrationForm).
     */
    public static function sanitize(array $input, ?Event $event = null): array
    {
        $filters = [];
        $formOptions = $event ? app(RegistrationForm::class)->filterOptions($event) : null;

        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? null;

            if ($key === 'answers') {
                $answers = self::sanitizeAnswers(is_array($value) ? $value : [], $formOptions['questions'] ?? []);
                if ($answers) {
                    $filters['answers'] = $answers;
                }

                continue;
            }

            if ($value === null || $value === '' || $value === false || is_array($value)) {
                continue;
            }

            $valid = match ($key) {
                'search' => is_string($value) && mb_strlen(trim($value)) <= 100,
                'mode' => in_array($value, ['online', 'in_person'], true),
                'payment_status' => in_array($value, self::PAYMENT_STATUSES, true),
                'industry' => in_array($value, config('seminar.industries'), true),
                'organisation_size' => in_array($value, config('seminar.organisation_sizes'), true),
                'interest' => in_array($value, $formOptions['interests'] ?? config('seminar.interests'), true),
                'networking_opt_in', 'lumko_opt_in', 'account_set_up' => in_array((string) $value, ['0', '1'], true),
                'checked_in_only', 'wants_follow_up' => in_array((string) $value, ['1', 'true'], true) || $value === true,
            };

            if (! $valid) {
                continue;
            }

            $filters[$key] = match ($key) {
                'search' => trim($value),
                'checked_in_only', 'wants_follow_up' => true,
                'networking_opt_in', 'lumko_opt_in', 'account_set_up' => (string) $value,
                default => $value,
            };
        }

        return $filters;
    }

    /** @return array<int, string> question id => option id / yes / no, for filterable questions only */
    private static function sanitizeAnswers(array $input, array $questions): array
    {
        $valid = [];

        foreach ($questions as $question) {
            $value = $input[$question['id']] ?? null;

            if (is_string($value) && in_array($value, array_column($question['options'], 'id'), true)) {
                $valid[$question['id']] = $value;
            }
        }

        return $valid;
    }

    public static function apply(Builder $query, array $filters, Event $event): Builder
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('organisation', 'like', "%{$search}%");
            });
        }

        if ($mode = $filters['mode'] ?? null) {
            $query->where('attendance_mode', $mode);
        }

        if ($paymentStatus = $filters['payment_status'] ?? null) {
            $query->whereHas('payment', fn ($q) => $q->where('status', $paymentStatus));
        }

        if (! empty($filters['checked_in_only'])) {
            $query->whereHas('ticket.checkIn');
        }

        if (isset($filters['networking_opt_in']) && $filters['networking_opt_in'] !== '') {
            $granted = filter_var($filters['networking_opt_in'], FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('user.consents', fn ($q) => $q
                ->where('event_id', $event->id)->where('type', 'networking')
                ->when($granted, fn ($q2) => $q2->whereNotNull('granted_at')->whereNull('revoked_at'), fn ($q2) => $q2->where(fn ($q3) => $q3->whereNull('granted_at')->orWhereNotNull('revoked_at'))));
        }

        // Lumko marketing consent is global and lives only in the ledger
        // (ConsentService never projects it into attendee_consents), so it
        // is read the same way ConsentService::isGranted() reads it.
        if (isset($filters['lumko_opt_in']) && $filters['lumko_opt_in'] !== '') {
            $granted = filter_var($filters['lumko_opt_in'], FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('user', fn ($q) => self::whereMarketingConsent($q, $granted));
        }

        if (! empty($filters['wants_follow_up'])) {
            $query->whereHas('user.feedback', fn ($q) => $q->where('event_id', $event->id)->where('wants_contact', true));
        }

        if ($industry = $filters['industry'] ?? null) {
            $query->where('industry', $industry);
        }

        if ($size = $filters['organisation_size'] ?? null) {
            $query->where('organisation_size', $size);
        }

        if ($interest = $filters['interest'] ?? null) {
            $query->whereJsonContains('interests', $interest);
        }

        // Answers to the event's own choice and yes/no questions.
        foreach ($filters['answers'] ?? [] as $questionId => $answer) {
            $query->whereHas('answers', function ($q) use ($questionId, $answer) {
                $q->where('registration_question_id', (int) $questionId);

                if ($answer === 'yes' || $answer === 'no') {
                    // A JSON boolean compares differently per database; the
                    // stored readable answer is always exactly Yes or No.
                    $q->where('display', $answer === 'yes' ? 'Yes' : 'No');
                } else {
                    // Single choice stores the id, multiple choice a list of ids.
                    $q->where(fn ($w) => $w->whereJsonContains('value', $answer)->orWhere('value', json_encode($answer)));
                }
            });
        }

        if (isset($filters['account_set_up']) && $filters['account_set_up'] !== '') {
            $setUp = filter_var($filters['account_set_up'], FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('user', fn ($q) => $setUp ? $q->whereNotNull('email_verified_at') : $q->whereNull('email_verified_at'));
        }

        return $query;
    }

    /**
     * Constrains a users query to people whose latest effective
     * lumko_marketing ledger entry is (or is not) a grant — the SQL form
     * of ConsentService::isGranted($user, ConsentType::LumkoMarketing).
     */
    public static function whereMarketingConsent(Builder $users, bool $granted = true): Builder
    {
        $latestAction = ConsentRecord::select('action')
            ->whereColumn('consent_records.user_id', 'users.id')
            ->where('type', ConsentType::LumkoMarketing->value)
            ->whereNotNull('effective_at')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->limit(1);

        if ($granted) {
            return $users->where($latestAction, 'granted');
        }

        return $users->where(fn ($q) => $q
            ->where($latestAction, 'withdrawn')
            ->orWhereDoesntHave('consentRecords', fn ($r) => $r
                ->where('type', ConsentType::LumkoMarketing->value)
                ->whereNotNull('effective_at')));
    }
}
