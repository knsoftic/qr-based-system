<?php

namespace App\Support;

/**
 * The staff permission catalogue. A super_admin bypasses all of these; a
 * manager or agent only has the keys stored on their user record. The admin
 * ticks these when creating / editing a staff member; defaultsFor() seeds a
 * sensible set when a role is picked.
 */
class Permissions
{
    /** @var array<string, array<string, string>> group label => [key => label] */
    public const GROUPS = [
        'Merchants' => [
            'merchants.view' => 'View merchants',
            'merchants.manage' => 'Create / edit merchants',
        ],
        'Merchant requests' => [
            'requests.submit' => 'Submit account-creation requests',
            'requests.review' => 'Approve / reject requests',
        ],
        'Payment accounts' => [
            'accounts.view' => 'View payment accounts',
            'accounts.create' => 'Add payment accounts',
            'accounts.delete' => 'Delete payment accounts',
            'accounts.manage' => 'Edit / toggle / maintenance / channels',
        ],
        'Transactions' => [
            'transactions.view' => 'View transactions',
            'transactions.manage' => 'Mark paid / failed',
            'callbacks.resend' => 'Resend callbacks',
        ],
        'Payouts' => [
            'payouts.view' => 'View payouts',
            'payouts.manage' => 'Approve / reject payouts',
        ],
        'Reports & logs' => [
            'reports.view' => 'View reports',
            'logs.view' => 'View logs',
            'settlements.view' => 'View settlements / earnings',
        ],
        'SMS forwarder' => [
            'sms.view' => 'View SMS inbox / devices',
            'sms.manage' => 'Manage SMS devices / settings',
        ],
        'Settings' => [
            'settings.manage' => 'Manage gateway settings',
            'checkout.manage' => 'Manage checkout page',
        ],
        'Staff' => [
            'staff.manage' => 'Manage staff, roles & permissions',
        ],
    ];

    /**
     * Every permission key.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = [];
        foreach (self::GROUPS as $group) {
            foreach ($group as $key => $label) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }

    public static function label(string $key): string
    {
        foreach (self::GROUPS as $group) {
            if (isset($group[$key])) {
                return $group[$key];
            }
        }

        return $key;
    }

    /**
     * Sensible starting permissions when a role is selected. Managers get
     * read-only tracking; agents can submit requests and see their own work.
     *
     * @return list<string>
     */
    public static function defaultsFor(string $role): array
    {
        return match ($role) {
            'super_admin' => self::all(),
            'manager' => [
                'merchants.view', 'accounts.view', 'transactions.view',
                'payouts.view', 'reports.view', 'logs.view', 'sms.view', 'settlements.view',
            ],
            'agent' => [
                'requests.submit', 'merchants.view', 'transactions.view', 'settlements.view',
            ],
            default => [],
        };
    }
}
