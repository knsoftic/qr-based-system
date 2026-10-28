<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Allowed state machine. An expired payment may still become paid
     * (late bank confirmation); every other final state is terminal.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => $to !== self::Pending,
            self::Expired => $to === self::Paid,
            default => false,
        };
    }
}
