<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Policies\Concerns\ScopesToAssignedEvent;

class PaymentPolicy
{
    use ScopesToAssignedEvent;

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->canAccessEvent($user, $payment->registration?->event_id);
    }

    public function update(User $user, Payment $payment): bool
    {
        return $this->canAccessEvent($user, $payment->registration?->event_id);
    }
}
