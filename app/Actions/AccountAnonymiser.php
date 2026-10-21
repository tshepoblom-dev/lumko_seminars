<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\AttendeeConsent;
use App\Models\EventRegistration;
use App\Models\Feedback;
use App\Models\PartnerConnection;
use App\Models\PartnerLeadNote;
use App\Models\RegistrationAnswer;
use App\Models\SpeakerConnection;
use App\Models\User;
use App\Models\Voucher;
use App\Notifications\AccountAnonymised;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * B12 — "delete my account" is implemented as anonymisation, not a hard
 * delete: the financial record (payments) and consent ledger must survive
 * (POPIA-defensible evidence of what was consented to and when), but with
 * no personal information left in them or in the user row.
 *
 * What's removed: name, email (tombstoned), mobile, organisation, job
 * title, password, sessions, and free-text the attendee wrote
 * (organisation_challenge, feedback takeaways, text/number/date/file
 * answers to registration questions and the files), and their name/email on
 * any invitation code (vouchers). Networking visibility is
 * dropped. What's kept: the registration/payment/ticket/consent rows
 * themselves (status, amounts, dates) — the minimum needed for financial
 * and compliance records — now pointing at an anonymised user.
 */
class AccountAnonymiser
{
    public function anonymise(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor) {
            $before = ['email' => $user->email, 'name' => $user->name];
            $tombstone = 'deleted-'.Str::lower((string) Str::ulid()).'@invalid';

            EventRegistration::where('user_id', $user->id)->update(['organisation_challenge' => null]);
            Feedback::where('user_id', $user->id)->update(['biggest_takeaway' => null]);

            // Registration answers they wrote or uploaded (text, numbers,
            // dates, files, and the files themselves). Choice and yes/no
            // answers stay for the counts, like interests.
            $registrationIds = EventRegistration::where('user_id', $user->id)->pluck('id');
            $personal = RegistrationAnswer::whereIn('event_registration_id', $registrationIds)
                ->whereHas('question', fn ($q) => $q->whereIn('type', ['short_text', 'long_text', 'number', 'date', 'file']))
                ->get();
            foreach ($personal as $answer) {
                if ($path = $answer->filePath()) {
                    Storage::disk('local')->delete($path);
                }
                $answer->delete();
            }

            // Drop networking visibility immediately — an anonymised user
            // has nothing meaningful to show in the directory anyway.
            AttendeeConsent::where('user_id', $user->id)
                ->where('type', 'networking')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            // Off every speaker's contact list straight away, and drop the
            // notes they wrote to speakers.
            SpeakerConnection::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            SpeakerConnection::where('user_id', $user->id)->update(['note' => null]);

            // Off every partner's leads straight away too, with their notes
            // (including enquiry messages) and the partner team's notes on them.
            PartnerConnection::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            PartnerConnection::where('user_id', $user->id)->update(['note' => null]);
            PartnerLeadNote::whereIn('partner_connection_id', PartnerConnection::where('user_id', $user->id)->select('id'))->delete();

            // Invitation codes they redeemed or that were issued to their
            // address (matched before the email is tombstoned below): the
            // code record stays — it explains a complimentary seat — but
            // not who it was for. An unused email-locked code stops working.
            Voucher::whereIn('id', EventRegistration::where('user_id', $user->id)->whereNotNull('voucher_id')->pluck('voucher_id'))
                ->orWhere('recipient_email', strtolower($user->email))
                ->update(['recipient_name' => 'Deleted user', 'recipient_email' => null, 'note' => null]);

            $user->forceFill([
                'name' => 'Deleted user',
                'first_name' => 'Deleted',
                'last_name' => 'user',
                'email' => $tombstone,
                'mobile' => null,
                'organisation' => null,
                'job_title' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'email_verified_at' => null,
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            Audit::record(
                'user.anonymised', subject: $user, actor: $actor ?? $user,
                before: $before, after: ['email' => $tombstone, 'name' => 'Deleted user'],
            );

            foreach (User::where('role', UserRole::SuperAdmin)->get() as $admin) {
                $admin->notify(new AccountAnonymised($user->id));
            }
        });
    }
}
