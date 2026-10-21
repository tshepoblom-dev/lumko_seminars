<?php

namespace App\Actions;

use App\Models\Event;
use App\Models\User;
use App\Notifications\ConfirmEmailAndSetPassword;
use Illuminate\Support\Facades\Password;

/**
 * The "confirm your email and set a password" link a guest registrant
 * receives. Used by the registration submit and by the admin "resend
 * setup link" actions, so both issue exactly the same email.
 *
 * The account was created with an unusable random password that is
 * never sent anywhere; this link is the only way in. An account that has
 * already been confirmed is left alone (it has a password of its own and
 * would use "forgot password" instead).
 */
class SendAccountSetupLink
{
    public function send(User $user, Event $event): bool
    {
        // Anonymised accounts (AccountAnonymiser) are unverified too, but
        // their address is a tombstone that must never be mailed.
        if ($user->hasVerifiedEmail() || self::isTombstone($user->email)) {
            return false;
        }

        // Broker tokens are single-use and one per user, so this replaces
        // any earlier link that is still outstanding.
        $token = Password::broker()->createToken($user);
        $complimentary = $user->registrations()->where('event_id', $event->id)->whereNotNull('voucher_id')->exists();
        $user->notify(new ConfirmEmailAndSetPassword($event, $token, $complimentary));

        return true;
    }

    public static function isTombstone(string $email): bool
    {
        return str_ends_with($email, '@invalid');
    }
}
