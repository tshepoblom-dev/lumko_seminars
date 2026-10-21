<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * B16 — the only supported way to create a staff account. Replaces the
 * seeder's old unconditional admin@lumko.cloud/password creation, which
 * shipped a known default credential into every environment including
 * production.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'lumko:create-admin {email} {--role=super_admin : super_admin or event_manager}';

    protected $description = 'Create (or promote) a staff account with a strong, operator-chosen password';

    /**
     * Common default/weak passwords we refuse outright, beyond the
     * strength rules below. Not exhaustive — a defence-in-depth check,
     * not a substitute for the length/character-class rules.
     */
    private const WEAK_PASSWORDS = [
        'password', 'password123', 'password1234', 'admin12345678',
        'letmein12345', 'changeme1234', 'welcome12345', 'qwertyuiop123',
        '12345678901234', 'administrator1',
    ];

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $roleValue = (string) $this->option('role');
        $role = UserRole::tryFrom($roleValue);

        if (! $role || ! $role->isStaff()) {
            $this->components->error("Invalid --role '{$roleValue}'. Use super_admin or event_manager.");

            return self::FAILURE;
        }

        $emailValidator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']]);
        if ($emailValidator->fails()) {
            $this->components->error("'{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        $password = $this->promptForPassword($email);
        if ($password === null) {
            return self::FAILURE;
        }

        $existing = User::where('email', $email)->first();

        $user = $existing ?? new User;
        $user->email = $email;
        $user->name = $user->name ?: Str::headline(explode('@', $email)[0]);
        $user->first_name = $user->first_name ?: Str::headline(explode('@', $email)[0]);
        $user->last_name = $user->last_name ?: '.';
        $user->password = $password; // 'hashed' cast on User handles hashing
        $user->email_verified_at = now();
        $user->save();

        app(RoleService::class)->assign($user, $role);

        $this->components->info(
            ($existing ? 'Updated' : 'Created')." staff account {$email} as {$role->label()}."
        );

        return self::SUCCESS;
    }

    private function promptForPassword(string $email): ?string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $password = $this->secret('Choose a password (min 14 characters, mixed case, letters and numbers)');
            $confirm = $this->secret('Confirm password');

            if ($password !== $confirm) {
                $this->components->warn('Passwords did not match. Try again.');

                continue;
            }

            $error = $this->passwordWeaknessReason($password ?? '', $email);
            if ($error !== null) {
                $this->components->warn($error);

                continue;
            }

            return $password;
        }

        $this->components->error('Too many invalid attempts. No account was created or changed.');

        return null;
    }

    private function passwordWeaknessReason(string $password, string $email): ?string
    {
        if (mb_strlen($password) < 14) {
            return 'Password must be at least 14 characters.';
        }

        if (! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            return 'Password must mix uppercase, lowercase and numbers.';
        }

        $lower = strtolower($password);
        foreach (self::WEAK_PASSWORDS as $weak) {
            if (str_contains($lower, $weak)) {
                return 'That password is too common/predictable. Choose another.';
            }
        }

        $localPart = strtolower(explode('@', $email)[0] ?? '');
        if ($localPart !== '' && str_contains($lower, $localPart)) {
            return 'Password must not contain your email address.';
        }

        return null;
    }
}
