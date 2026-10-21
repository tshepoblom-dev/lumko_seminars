<?php

namespace App\Http\Requests\Admin;

use App\Support\ResourceRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('resource'));
    }

    /** The event comes from the workspace URL, never from the form. */
    protected function prepareForValidation(): void
    {
        $this->merge(['event_id' => $this->route('event')?->id]);
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'exists:events,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'in:before_event,during_event,after_event'],
            'file' => ResourceRules::file(),
            'external_url' => ResourceRules::externalUrl(),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $resource = $this->route('resource');
            $keepingExisting = $resource->file_path || $resource->external_url;

            if (! $this->hasFile('file') && ! $this->filled('external_url') && ! $keepingExisting) {
                $validator->errors()->add('file', 'Provide either a file or an external link.');
            }
        });
    }
}
