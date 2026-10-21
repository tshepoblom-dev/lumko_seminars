<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending proof of payment',
            self::UnderReview => 'Under review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * B4 — the payment state machine. Approve/reject/cancel must all go
     * through PaymentService, which checks this before mutating anything.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => in_array($to, [self::UnderReview, self::Approved, self::Cancelled], true),
            self::UnderReview => in_array($to, [self::UnderReview, self::Approved, self::Rejected, self::Cancelled], true),
            self::Rejected => in_array($to, [self::UnderReview, self::Cancelled], true),
            self::Approved => $to === self::Cancelled,
            self::Cancelled => false,
        };
    }
}
