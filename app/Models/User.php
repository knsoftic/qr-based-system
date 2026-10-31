<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER = 'super_admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_AGENT = 'agent';

    public const ROLES = [
        self::ROLE_SUPER => 'Super Admin',
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_AGENT => 'Agent',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'role',
        'permissions',
        'parent_id',
        'salary',
        'payin_commission',
        'payout_commission',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'permissions' => 'array',
            'salary' => 'decimal:2',
            'payin_commission' => 'decimal:2',
            'payout_commission' => 'decimal:2',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Staff who report to this user (e.g. a manager's agents). */
    public function team(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Merchants in this staff member's downline: the ones they own directly
     * plus the ones owned by their team (a manager's agents). Used for
     * commission / settlement and read-only tracking.
     *
     * @return list<int>
     */
    public function downlineMerchantIds(): array
    {
        $staffIds = array_merge([$this->id], $this->team()->pluck('id')->all());

        return Merchant::query()->whereIn('agent_id', $staffIds)->pluck('id')->all();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER;
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isAgent(): bool
    {
        return $this->role === self::ROLE_AGENT;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /**
     * A super_admin can do everything; everyone else is limited to the
     * permission keys stored on their record.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($key, (array) ($this->permissions ?? []), true);
    }

    /**
     * True when the user has any of the given permissions.
     */
    public function hasAnyPermission(string ...$keys): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        foreach ($keys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }
}
