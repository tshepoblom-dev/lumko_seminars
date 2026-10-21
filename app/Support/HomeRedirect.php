<?php

namespace App\Support;

use App\Models\User;

/**
 * Single place that decides where a user lands after login, email
 * verification and password confirmation. Staff go to the admin area,
 * speakers to their portal, partner reps to their partner's portal,
 * everyone else to the attendee dashboard. Anyone with more than one of
 * these reaches the others from the portal switcher.
 */
class HomeRedirect
{
    public static function for(?User $user): string
    {
        if ($user === null) {
            return route('home', absolute: false);
        }

        if ($user->isStaff()) {
            return route('admin.dashboard', absolute: false);
        }

        if ($user->isSpeaker()) {
            return route('speaker.dashboard', absolute: false);
        }

        $partner = $user->partners()->first();

        if ($partner) {
            return route('partner.dashboard', $partner, absolute: false);
        }

        return route('attendee.dashboard', absolute: false);
    }
}
