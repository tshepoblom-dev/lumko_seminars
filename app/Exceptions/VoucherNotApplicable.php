<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * A valid invitation code that can't be applied to this particular
 * registration (already complimentary, a proof of payment under review, a
 * ticket already confirmed…). Kept apart from plain code errors because
 * these depend on the account's state: the anonymous registration form
 * swallows them so its response never reveals anything about an account
 * (B2), while signed-in pages show them as ordinary validation errors.
 */
class VoucherNotApplicable extends ValidationException {}
