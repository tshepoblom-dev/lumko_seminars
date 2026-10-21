<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLivestreamSettingsRequest extends FormRequest
{
    private const ALLOWED_HOSTS = [
        'youtube' => ['youtube.com', 'www.youtube.com', 'youtu.be'],
        'facebook' => ['facebook.com', 'www.facebook.com', 'fb.watch'],
    ];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'livestream_provider' => ['nullable', 'in:youtube,facebook,agora'],
            'livestream_url' => ['nullable', 'required_if:livestream_provider,youtube,facebook', 'url:https', 'max:2048'],
            'livestream_channel_name' => ['nullable', 'required_if:livestream_provider,agora', 'regex:/^[A-Za-z0-9 _-]{1,64}$/'],

            // C3 — secondary/fallback provider
            'livestream_secondary_provider' => ['nullable', Rule::in(['youtube', 'facebook']), 'different:livestream_provider'],
            'livestream_secondary_url' => ['nullable', 'required_with:livestream_secondary_provider', 'url:https', 'max:2048'],

            'recording_url' => ['nullable', 'url:https', 'max:2048'],
            'show_reactions_to_attendees' => ['boolean'],
            'live_lead_minutes' => ['nullable', 'integer', 'min:0', 'max:180'],
        ];
    }

    /**
     * C3 — host allow-list per provider: a youtube/facebook URL must
     * actually point at that provider's domain, not just be https.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->assertAllowedHost($validator, $this->input('livestream_provider'), $this->input('livestream_url'), 'livestream_url');
            $this->assertAllowedHost($validator, $this->input('livestream_secondary_provider'), $this->input('livestream_secondary_url'), 'livestream_secondary_url');
        });
    }

    private function assertAllowedHost($validator, ?string $provider, ?string $url, string $field): void
    {
        if (! $provider || ! $url || ! isset(self::ALLOWED_HOSTS[$provider])) {
            return;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($host, self::ALLOWED_HOSTS[$provider], true)) {
            $allowed = implode(', ', self::ALLOWED_HOSTS[$provider]);
            $validator->errors()->add($field, "This must be a {$provider} link ({$allowed}).");
        }
    }
}
