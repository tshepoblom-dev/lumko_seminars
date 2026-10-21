<?php

namespace App\Services;

use App\Models\AttendeeConsent;
use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Payment;
use App\Models\PollResponse;
use App\Models\RegistrationAnswer;
use App\Models\RegistrationQuestion;
use App\Models\ResourceView;
use App\Models\User;
use App\Support\RegistrantFilter;
use Illuminate\Support\Carbon;

/**
 * D-Admin dashboard (spec §21) — the aggregate counts shown on both the
 * admin dashboard's event cards and the per-event Engagement page. One
 * place computes these so the two pages can never quietly disagree.
 */
class EventStatsService
{
    public function summary(Event $event): array
    {
        $registrations = $event->registrations();

        return [
            'registered_total' => (clone $registrations)->count(),
            'registered_online' => (clone $registrations)->where('attendance_mode', 'online')->count(),
            'registered_in_person' => (clone $registrations)->where('attendance_mode', 'in_person')->count(),
            'checked_in' => CheckIn::whereHas('ticket.registration', fn ($q) => $q->where('event_id', $event->id))->count(),
            'questions_submitted' => $event->questions()->count(),
            'questions_approved' => $event->questions()->approved()->count(),
            'poll_responses' => PollResponse::whereHas('poll', fn ($q) => $q->where('event_id', $event->id))->count(),
            'resource_views' => ResourceView::whereHas('resource', fn ($q) => $q->where('event_id', $event->id))->count(),
            'networking_opt_ins' => AttendeeConsent::where('event_id', $event->id)
                ->where('type', 'networking')->whereNotNull('granted_at')->whereNull('revoked_at')->count(),
            // Marketing consent is global and lives only in the ledger (it is
            // never projected into attendee_consents), so it is counted the
            // way the attendee list filters it: this event's registrants
            // whose latest effective entry is a grant.
            'lumko_marketing_opt_ins' => RegistrantFilter::whereMarketingConsent(
                User::whereHas('registrations', fn ($q) => $q->where('event_id', $event->id)),
            )->count(),
            'feedback_count' => $event->feedback()->count(),
            'feedback_average_rating' => $event->feedback()->count() ? round($event->feedback()->avg('rating'), 1) : null,
            'feedback_wants_contact' => $event->feedback()->where('wants_contact', true)->count(),
        ];
    }

    /**
     * The extra breakdowns the dashboard cards need beyond the shared
     * summary above: payment status/amount totals, mode/organisation/
     * industry/job-title/interest breakdowns, and a daily registration
     * count for the last 30 days (a simple inline-SVG sparkline, not a
     * charting library dependency).
     */
    public function dashboardExtras(Event $event): array
    {
        $registrationIds = $event->registrations()->pluck('id');

        $paymentsByStatus = Payment::whereIn('event_registration_id', $registrationIds)
            ->selectRaw('status, count(*) as count, sum(amount) as total')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        // Invitation-code seats are approved R0 payments: counted on their
        // own, never as "paid" (verified_amount is unaffected — they're 0).
        $complimentary = Payment::whereIn('event_registration_id', $event->registrations()->whereNotNull('voucher_id')->pluck('id'))
            ->where('status', 'approved')
            ->count();

        $breakdown = function (string $userColumn) use ($event) {
            return $event->registrations()
                ->join('users', 'users.id', '=', 'event_registrations.user_id')
                ->selectRaw("users.{$userColumn} as label, count(*) as count")
                ->whereNotNull("users.{$userColumn}")
                ->where("users.{$userColumn}", '!=', '')
                ->groupBy("users.{$userColumn}")
                ->orderByDesc('count')
                ->limit(5)
                ->get();
        };

        $registrationsOverTime = $event->registrations()
            ->selectRaw('DATE(created_at) as date, count(*) as count')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'payment_totals' => [
                'pending' => (int) ($paymentsByStatus->get('pending')?->count ?? 0),
                'under_review' => (int) ($paymentsByStatus->get('under_review')?->count ?? 0),
                'approved' => (int) ($paymentsByStatus->get('approved')?->count ?? 0) - $complimentary,
                'complimentary' => $complimentary,
                'rejected' => (int) ($paymentsByStatus->get('rejected')?->count ?? 0),
                'cancelled' => (int) ($paymentsByStatus->get('cancelled')?->count ?? 0),
                'verified_amount' => (float) ($paymentsByStatus->get('approved')?->total ?? 0),
            ],
            'by_organisation' => $breakdown('organisation'),
            'by_industry' => $event->registrations()
                ->selectRaw('industry as label, count(*) as count')
                ->whereNotNull('industry')->where('industry', '!=', '')
                ->groupBy('industry')->orderByDesc('count')->limit(5)->get(),
            'by_job_title' => $breakdown('job_title'),
            // interests is a JSON array column — counting per-interest
            // needs unwinding, which isn't portable across SQLite/MySQL as
            // a single query; the event's attendee list is small enough
            // (tens to low hundreds) that counting in PHP is simpler and
            // just as fast in practice than a JSON_TABLE query.
            'by_interest' => $this->topListAnswers($event, 'interests'),
            'by_improvement_area' => $this->topListAnswers($event, 'improvement_area'),
            // The event's own choice and yes/no questions, top five answers each.
            'by_question' => $this->topQuestionAnswers($event),
            'registrations_over_time' => $registrationsOverTime->map(fn ($row) => [
                'date' => $row->date, 'count' => $row->count,
            ]),
        ];
    }

    /**
     * For each active, non-standard choice or yes/no question: its five
     * most common answers, counted in PHP like topListAnswers(). Option
     * labels are today's; an answer to a since-hidden option still counts.
     */
    private function topQuestionAnswers(Event $event): array
    {
        $questions = app(RegistrationForm::class)->questions($event)
            ->filter(fn (RegistrationQuestion $q) => ! $q->isBuiltIn() && $q->isFilterable());

        return $questions->map(function (RegistrationQuestion $q) {
            $counts = RegistrationAnswer::where('registration_question_id', $q->id)
                ->whereHas('registration', fn ($r) => $r->where('status', 'registered'))
                ->pluck('value')
                ->flatMap(fn ($value) => is_array($value) ? $value : [$value])
                ->map(fn ($value) => match (true) {
                    is_bool($value) => $value ? 'Yes' : 'No',
                    default => $q->optionLabel((string) $value) ?? (string) $value,
                })
                ->countBy()
                ->sortDesc()
                ->take(5);

            return [
                'question' => $q->label,
                'rows' => $counts->map(fn ($count, $label) => ['label' => $label, 'count' => $count])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * The five most-chosen answers in a JSON-array registration column
     * (interests, improvement_area), counted in PHP — see the note above.
     */
    private function topListAnswers(Event $event, string $column)
    {
        return $event->registrations()->whereNotNull($column)->pluck($column)
            ->flatMap(fn ($answers) => $answers ?? [])
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->map(fn ($count, $label) => ['label' => $label, 'count' => $count])
            ->values();
    }
}
