<?php

namespace App\Support;

use App\Models\PaymentAccount;
use Illuminate\Support\Collection;

/**
 * Payment-method "channels" (easypaisa, JazzCash, Raast, a bank, ...). Accounts
 * are grouped by their channel so an operator can put a whole method into
 * maintenance at once — every account of that channel stops receiving new
 * payments, while other channels and the site keep working. Maintenance state
 * is kept in settings ("channels.maintenance"); the channels themselves come
 * from the accounts.
 */
class Channels
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Channel names currently in maintenance (as stored).
     *
     * @return list<string>
     */
    public function maintenanceNames(): array
    {
        $list = $this->settings->get('channels.maintenance', []);
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($c) => trim((string) $c), $list),
            fn ($c) => $c !== '',
        ));
    }

    public function inMaintenance(?string $channel): bool
    {
        $channel = trim((string) $channel);
        if ($channel === '') {
            return false;
        }

        foreach ($this->maintenanceNames() as $name) {
            if (strcasecmp($name, $channel) === 0) {
                return true;
            }
        }

        return false;
    }

    public function setMaintenance(string $channel, bool $on): void
    {
        $channel = trim($channel);
        if ($channel === '') {
            return;
        }

        $names = collect($this->maintenanceNames())
            ->reject(fn ($n) => strcasecmp($n, $channel) === 0)
            ->values();

        if ($on) {
            $names->push($channel);
        }

        $this->settings->set(['channels.maintenance' => $names->values()->all()]);
    }

    /**
     * Distinct channels across accounts, with counts and maintenance state.
     *
     * @return Collection<int, array{name: string, total: int, active: int, maintenance: bool}>
     */
    public function overview(): Collection
    {
        return PaymentAccount::query()->get(['id', 'channel', 'is_active'])
            ->filter(fn (PaymentAccount $a) => filled($a->channel))
            ->groupBy(fn (PaymentAccount $a) => trim((string) $a->channel))
            ->map(fn (Collection $group, string $name) => [
                'name' => $name,
                'total' => $group->count(),
                'active' => $group->where('is_active', true)->count(),
                'maintenance' => $this->inMaintenance($name),
            ])
            ->sortKeys()
            ->values();
    }

    /**
     * Channel names in use (for form suggestions).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->overview()->pluck('name')->all();
    }
}
