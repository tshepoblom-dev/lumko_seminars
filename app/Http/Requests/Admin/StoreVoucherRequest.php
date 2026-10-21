<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Event → Vouchers: issue one invitation code. The event comes from the
 * workspace URL, never from the form. An email address is optional, but
 * needed to lock the code to it or to email the invitation.
 */
class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('recipient_email');
        $email = is_string($email) ? strtolower(trim($email)) : $email;

        $this->merge(['recipient_email' => $email === '' ? null : $email]);
    }

    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email:rfc', 'max:255', 'required_if_accepted:email_locked', 'required_if_accepted:send_now'],
            'email_locked' => ['boolean'],
            'send_now' => ['boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_email.required_if_accepted' => 'Add their email address to reserve the code for them or to email the invitation.',
        ];
    }
}
