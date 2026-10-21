<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_registration_id',
        'amount',
        'status',
        'proof_path',
        'proof_original_name',
        'admin_note',
        'manual_override',
        'reviewed_by',
        'reviewed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'manual_override' => 'boolean',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function registration()
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusHistory()
    {
        return $this->hasMany(PaymentStatusHistory::class);
    }
}
