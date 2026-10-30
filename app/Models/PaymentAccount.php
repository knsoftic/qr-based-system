<?php

namespace App\Models;

use App\Enums\DecodeStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'provider',
        'priority',
        'daily_limit',
        'monthly_limit',
        'is_active',
        'maintenance',
    ];

    /**
     * The original payload and the parsed template are credentials: they are
     * encrypted at rest and never serialised.
     */
    protected $hidden = [
        'raw_payload',
        'payload_template',
        'payload_hash',
        'qr_image_path',
    ];

    protected function casts(): array
    {
        return [
            'decode_status' => DecodeStatus::class,
            'raw_payload' => 'encrypted',
            'payload_template' => 'encrypted:array',
            'payload_summary' => 'array',
            'is_active' => 'boolean',
            'maintenance' => 'boolean',
            'priority' => 'integer',
            'daily_limit' => 'decimal:2',
            'monthly_limit' => 'decimal:2',
            'last_used_at' => 'datetime',
            'decoded_at' => 'datetime',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function monthlyStats(): HasMany
    {
        return $this->hasMany(AccountMonthlyStat::class)->orderByDesc('year')->orderByDesc('month');
    }

    /**
     * Active, decoded, not in manual maintenance. Over-limit is checked per
     * amount by canAccept(), since it depends on the payment amount.
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('maintenance', false)
            ->where('decode_status', DecodeStatus::Decoded->value);
    }

    public function isDecoded(): bool
    {
        return $this->decode_status === DecodeStatus::Decoded;
    }

    /**
     * Paid amount received today / this calendar month (resets on the 1st).
     */
    public function usedToday(): float
    {
        return (float) $this->paidSince(now()->startOfDay());
    }

    public function usedThisMonth(): float
    {
        return (float) $this->paidSince(now()->startOfMonth());
    }

    private function paidSince(\DateTimeInterface $since): string
    {
        return (string) $this->payments()
            ->where('status', PaymentStatus::Paid->value)
            ->where('paid_at', '>=', $since)
            ->sum('amount');
    }

    /**
     * Can this account take a new payment of $amount right now?
     */
    public function canAccept(float $amount = 0): bool
    {
        if (! $this->is_active || $this->maintenance || ! $this->isDecoded()) {
            return false;
        }

        if ($this->daily_limit !== null && $this->usedToday() + $amount > (float) $this->daily_limit) {
            return false;
        }

        if ($this->monthly_limit !== null && $this->usedThisMonth() + $amount > (float) $this->monthly_limit) {
            return false;
        }

        return true;
    }

    /**
     * Why the account is unavailable ("maintenance mode"), or null when usable.
     */
    public function maintenanceReason(): ?string
    {
        if (! $this->is_active) {
            return 'Disabled';
        }
        if (! $this->isDecoded()) {
            return 'QR not decoded';
        }
        if ($this->maintenance) {
            return 'In maintenance (manual)';
        }
        if ($this->daily_limit !== null && $this->usedToday() >= (float) $this->daily_limit) {
            return 'Daily limit reached';
        }
        if ($this->monthly_limit !== null && $this->usedThisMonth() >= (float) $this->monthly_limit) {
            return 'Monthly limit reached';
        }

        return null;
    }

    public function inMaintenance(): bool
    {
        return $this->maintenanceReason() !== null;
    }

    public function providerName(): string
    {
        if ($this->provider === \App\Payments\Providers\ProviderRegistry::AUTO) {
            return 'Auto-detect (not recognised)';
        }

        return config("gateway.providers.{$this->provider}.name", $this->provider);
    }

    /**
     * Structured lifetime + this-month figures for the account page.
     */
    public function stats(): array
    {
        $agg = fn ($query) => $query->selectRaw('COUNT(*) attempts')
            ->selectRaw("SUM(status='paid') verified")
            ->selectRaw("SUM(status='expired') expired")
            ->selectRaw("SUM(status='failed') failed")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) received")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN fee_amount ELSE 0 END) fees")
            ->selectRaw("SUM(CASE WHEN status='paid' THEN net_amount ELSE 0 END) net")
            ->first();

        $all = $agg($this->payments());
        $month = $agg($this->payments()->where('created_at', '>=', now()->startOfMonth()));

        return [
            'all' => $this->shape($all),
            'month' => $this->shape($month),
            'used_today' => $this->usedToday(),
            'used_month' => $this->usedThisMonth(),
        ];
    }

    private function shape($row): array
    {
        $attempts = (int) ($row->attempts ?? 0);
        $verified = (int) ($row->verified ?? 0);

        return [
            'attempts' => $attempts,
            'verified' => $verified,
            'expired' => (int) ($row->expired ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'received' => (float) ($row->received ?? 0),
            'fees' => (float) ($row->fees ?? 0),
            'net' => (float) ($row->net ?? 0),
            'success_rate' => $attempts ? round($verified / $attempts * 100, 1) : 0.0,
        ];
    }
}
