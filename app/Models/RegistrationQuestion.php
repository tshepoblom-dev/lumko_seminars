<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One question on an event's registration form (App\Services\
 * RegistrationForm). Built-in questions carry a `key` and keep their type;
 * choice options are {id, label, hidden} and are hidden, never removed.
 */
class RegistrationQuestion extends Model
{
    use HasFactory;

    public const TYPES = ['single_choice', 'multi_choice', 'short_text', 'long_text', 'yes_no', 'number', 'date', 'file'];

    public const CHOICE_TYPES = ['single_choice', 'multi_choice'];

    /** Mirrored into the event_registrations column of the same name. */
    public const KEYS = ['interests', 'improvement_area', 'organisation_challenge'];

    protected $fillable = [
        'event_id',
        'key',
        'page',
        'type',
        'label',
        'help_text',
        'options',
        'settings',
        'required',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'settings' => 'array',
            'required' => 'boolean',
            'is_active' => 'boolean',
            'page' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function answers()
    {
        return $this->hasMany(RegistrationAnswer::class);
    }

    public function isChoice(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    /** Choice and yes/no answers can be filtered and counted. */
    public function isFilterable(): bool
    {
        return $this->isChoice() || $this->type === 'yes_no';
    }

    public function isBuiltIn(): bool
    {
        return $this->key !== null;
    }

    /** @return list<array{id: string, label: string, hidden: bool}> */
    public function visibleOptions(): array
    {
        return array_values(array_filter($this->options ?? [], fn ($o) => empty($o['hidden'])));
    }

    public function optionLabel(string $id): ?string
    {
        foreach ($this->options ?? [] as $option) {
            if ($option['id'] === $id) {
                return $option['label'];
            }
        }

        return null;
    }

    public function setting(string $name, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$name] ?? $default;
    }
}
