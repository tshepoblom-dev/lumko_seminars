<?php

namespace App\Http\Requests\Admin;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Event::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:events,slug'],
            'description' => ['nullable', 'string'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'venue_address' => ['nullable', 'string'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'online_price' => ['required', 'numeric', 'min:0'],
            'in_person_price' => ['required', 'numeric', 'min:0'],
            'offers_online' => ['sometimes', 'boolean'],
            'status' => ['required', 'in:draft,published,completed'],
            // Landing-page photography, one hero and one venue image per event.
            'hero_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'hero_image_alt' => ['nullable', 'required_with:hero_image', 'string', 'max:255'],
            'venue_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'venue_image_alt' => ['nullable', 'required_with:venue_image', 'string', 'max:255'],
        ];
    }
}
