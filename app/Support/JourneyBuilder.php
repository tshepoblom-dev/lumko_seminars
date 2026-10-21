<?php

namespace App\Support;

use App\Enums\AttendanceMode;
use App\Models\EventRegistration;
use App\Models\Feedback;
use App\Models\Question;
use App\Models\Resource;
use App\Models\ResourceView;
use Carbon\CarbonInterface;

/**
 * D-Attendee dashboard (spec §11) — computes the attendee's ordered
 * "event journey" and picks a single next-step callout from it. Every
 * step's completeness is a real, queryable fact (payment status, a row
 * in `questions`, a row in `resource_views`, a submitted `Feedback`) —
 * nothing here is a UI-only checkbox with no backing state. Steps that
 * don't apply to this attendance mode (payment/ticket for an online
 * attendee) are left out of the chain entirely rather than shown
 * pre-completed.
 */
class JourneyBuilder
{
    public static function build(EventRegistration $registration, CarbonInterface $now): array
    {
        $event = $registration->event;
        $isInPerson = $registration->attendance_mode === AttendanceMode::InPerson;
        $hasEnded = $event->ends_at?->lte($now) ?? false;
        $withinLiveWindow = $event->isWithinLiveLeadWindow();

        $steps = [];

        if ($isInPerson) {
            // An invitation code (VoucherService) waived the payment, so
            // there is no payment step to show — not even a completed one.
            $complimentary = $registration->isComplimentary();
            $paymentApproved = $registration->payment?->status?->value === 'approved';

            if (! $complimentary) {
                $steps[] = self::step(
                    'payment',
                    'Complete your payment',
                    'Upload proof of payment so we can confirm your ticket.',
                    route('payment.show'),
                    complete: $paymentApproved,
                    available: true,
                );
            }

            $ticketConfirmed = $registration->ticket?->status?->value === 'confirmed';
            $steps[] = self::step(
                'ticket_confirmed',
                'Ticket confirmed',
                $complimentary ? 'Your complimentary ticket is ready.' : 'Your ticket is issued once your payment is approved.',
                route('ticket.show'),
                complete: $ticketConfirmed,
                available: $paymentApproved,
            );
        }

        // join_live is listed before submit_question: once the live window
        // opens both become "current" simultaneously, and joining the
        // session is the more urgent of the two — the question form lives
        // on that same Live page anyway.
        $steps[] = self::step(
            'join_live',
            $hasEnded ? 'Live session' : 'Join the live session',
            match (true) {
                $hasEnded => 'The live session has ended.',
                $withinLiveWindow => 'The live session is open — join from the Live page.',
                default => 'The Live page opens at '.$event->liveOpensAt()?->format('H:i').' on '.$event->starts_at?->format('j F').'.',
            },
            route('live.show'),
            complete: $hasEnded,
            available: $withinLiveWindow,
        );

        $askedQuestion = Question::where('event_id', $event->id)->where('user_id', $registration->user_id)->exists();
        $steps[] = self::step(
            'submit_question',
            'Submit a question for the speakers',
            'Send in a question ahead of or during the live session.',
            route('live.show'),
            complete: $askedQuestion,
            available: $withinLiveWindow || $hasEnded,
        );

        $availableResources = Resource::availableFor($event, $event->currentSession, $now);
        $viewedResource = $availableResources->isNotEmpty() && ResourceView::where('user_id', $registration->user_id)
            ->whereIn('resource_id', $availableResources->pluck('id'))
            ->exists();
        $steps[] = self::step(
            'resources',
            'Access event resources',
            'Slides, recordings and other materials shared by the organisers.',
            route('resources.index'),
            complete: $viewedResource,
            available: $availableResources->isNotEmpty(),
        );

        $feedbackWindowOpen = $hasEnded && $event->ends_at->copy()->addDays((int) config('seminar.feedback_days', 30))->isFuture();
        $feedbackGiven = Feedback::where('event_id', $event->id)->where('user_id', $registration->user_id)->exists();
        $steps[] = self::step(
            'feedback',
            'Share your feedback',
            'Tell us what you thought and what you\'d like to see next.',
            route('feedback.create'),
            complete: $feedbackGiven,
            available: $feedbackWindowOpen,
        );

        return self::withStatuses($steps);
    }

    /**
     * The single most relevant step to surface as the dashboard's
     * headline callout: the first available-but-incomplete step, else
     * the first not-yet-available step (shown as "coming up"), else null
     * when every step is complete.
     */
    public static function nextStep(array $steps): ?array
    {
        foreach ($steps as $step) {
            if ($step['status'] === 'current') {
                return $step;
            }
        }

        foreach ($steps as $step) {
            if ($step['status'] === 'upcoming') {
                return $step;
            }
        }

        return null;
    }

    private static function step(string $key, string $label, string $hint, string $url, bool $complete, bool $available): array
    {
        return compact('key', 'label', 'hint', 'url', 'complete', 'available');
    }

    private static function withStatuses(array $steps): array
    {
        return array_map(function (array $step) {
            $status = match (true) {
                $step['complete'] => 'complete',
                $step['available'] => 'current',
                default => 'upcoming',
            };

            return [
                'key' => $step['key'],
                'label' => $step['label'],
                'hint' => $step['hint'],
                'url' => $step['url'],
                'status' => $status,
            ];
        }, $steps);
    }
}
