<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallbackDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_id',
        'url',
        'payload',
        'attempt',
        'response_code',
        'response_body',
        'error',
        'success',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
