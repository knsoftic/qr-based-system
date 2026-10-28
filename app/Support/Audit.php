<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Records admin actions. Sensitive values are always redacted before they
 * reach the audit table.
 */
class Audit
{
    private const REDACTED_KEYS = [
        'password',
        'api_secret',
        'raw_payload',
        'payload_template',
        'qr_payload',
        'remember_token',
        'verification.key_hash',
    ];

    /**
     * $byAdmin = false for actions a merchant takes in the portal, so they are
     * never attributed to an admin signed in on the same browser.
     */
    public static function log(string $action, ?Model $subject = null, array $old = [], array $new = [], bool $byAdmin = true): AuditLog
    {
        $request = request();

        return AuditLog::query()->create([
            'user_id' => $byAdmin ? auth('web')->id() : null,
            'action' => $action,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'old_values' => self::redact($old) ?: null,
            'new_values' => self::redact($new) ?: null,
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
        ]);
    }

    /**
     * Only keep the attributes that actually changed on an updated model.
     */
    public static function changes(Model $model): array
    {
        $changed = $model->getChanges();
        unset($changed['updated_at']);

        return [
            Arr::only($model->getOriginal(), array_keys($changed)),
            $changed,
        ];
    }

    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, self::REDACTED_KEYS, true)) {
                $values[$key] = '[redacted]';
            } elseif ($value instanceof \BackedEnum) {
                $values[$key] = $value->value;
            }
        }

        return $values;
    }
}
