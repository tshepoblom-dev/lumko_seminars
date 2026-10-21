<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An attendee's answer to one registration question. `question_label` and
 * `display` are what the attendee saw and said, so later edits to the
 * question never change an answer's meaning. A file answer's `value` is
 * {path, name, size, mime} on the private disk.
 */
class RegistrationAnswer extends Model
{
    protected $fillable = [
        'event_registration_id',
        'registration_question_id',
        'value',
        'display',
        'question_label',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function registration()
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function question()
    {
        return $this->belongsTo(RegistrationQuestion::class, 'registration_question_id');
    }

    public function filePath(): ?string
    {
        return is_array($this->value) ? ($this->value['path'] ?? null) : null;
    }
}
