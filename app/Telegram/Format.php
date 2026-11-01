<?php

namespace App\Telegram;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Small formatting helpers shared by the notifier and the command router.
 */
class Format
{
    public static function money(mixed $amount, string $currency = 'PKR'): string
    {
        return $currency.' '.number_format((float) $amount, 2);
    }

    public static function dt(?DateTimeInterface $date): string
    {
        if (! $date) {
            return '—';
        }

        return Carbon::instance($date)->timezone('Asia/Karachi')->format('d M, H:i');
    }
}
