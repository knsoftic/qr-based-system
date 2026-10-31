<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Admin-managed look and content of the customer checkout page: logo, colours,
 * Urdu/RTL text, font (Noto Nastaliq Urdu or an uploaded custom font), Telegram
 * link, a custom button, and the allowed mobile-operator codes for the number
 * the customer enters. Values come from App\Support\Settings ("checkout.*").
 */
class CheckoutTheme
{
    /** @var array<string, mixed> */
    private array $all;

    public function __construct(Settings $settings)
    {
        $this->all = $settings->all();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all['checkout.'.$key] ?? $default;
    }

    public function lang(): string
    {
        return $this->get('language', 'ur') === 'en' ? 'en' : 'ur';
    }

    public function isRtl(): bool
    {
        return $this->lang() === 'ur';
    }

    public function primaryColor(): string
    {
        return $this->hex($this->get('primary_color'), '#6366f1');
    }

    public function accentColor(): string
    {
        return $this->hex($this->get('accent_color'), '#8b5cf6');
    }

    public function gradient(): string
    {
        return 'linear-gradient(135deg, '.$this->primaryColor().', '.$this->accentColor().')';
    }

    public function logoUrl(): ?string
    {
        $path = $this->get('logo_path');

        return $path ? asset('branding/'.$path) : null;
    }

    public function customFontUrl(): ?string
    {
        $path = $this->get('font_path');

        return $path ? asset('branding/'.$path) : null;
    }

    /** True when the page should load/use a Nastaliq Urdu face. */
    public function usesNastaliq(): bool
    {
        return $this->get('font', 'nastaliq') === 'nastaliq';
    }

    public function needsGoogleFont(): bool
    {
        return $this->usesNastaliq();
    }

    /** CSS font-family stack for the page body. */
    public function fontFamily(): string
    {
        if (! $this->usesNastaliq()) {
            return "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
        }

        $stack = [];
        if ($this->customFontUrl()) {
            $stack[] = "'CheckoutUrdu'";
        }
        $stack[] = "'Noto Nastaliq Urdu'";
        $stack[] = 'serif';

        return implode(', ', $stack);
    }

    public function telegramUrl(): ?string
    {
        $value = trim((string) $this->get('telegram'));
        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        $handle = ltrim($value, '@/');
        $handle = Str::afterLast($handle, 't.me/');

        return 'https://t.me/'.$handle;
    }

    /**
     * @return array{label: string, url: string}|null
     */
    public function button(): ?array
    {
        $label = trim((string) $this->get('button_label'));
        $url = trim((string) $this->get('button_url'));

        if ($label === '' || ! Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }

        return ['label' => $label, 'url' => $url];
    }

    /** @return list<string> */
    public function steps(): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $this->get('steps', '')))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Enabled operator-code groups.
     *
     * @return list<array{label: string, prefixes: string, enabled: bool}>
     */
    public function codes(): array
    {
        $codes = $this->get('codes', []);

        return is_array($codes) ? $codes : [];
    }

    /**
     * All enabled 4-digit prefixes (e.g. "0300").
     *
     * @return list<string>
     */
    public function allowedPrefixes(): array
    {
        $prefixes = [];
        foreach ($this->codes() as $group) {
            if (empty($group['enabled'])) {
                continue;
            }
            foreach (preg_split('/[,\s]+/', (string) ($group['prefixes'] ?? '')) as $p) {
                $p = preg_replace('/\D/', '', $p);
                if (strlen($p) === 4 && str_starts_with($p, '03')) {
                    $prefixes[$p] = $p;
                }
            }
        }

        return array_values($prefixes);
    }

    /**
     * Is a (normalised 03XXXXXXXXX) number from an allowed operator? With no
     * configured codes there is no restriction.
     */
    public function allows(string $normalisedPhone): bool
    {
        $allowed = $this->allowedPrefixes();
        if ($allowed === []) {
            return true;
        }

        return in_array(substr($normalisedPhone, 0, 4), $allowed, true);
    }

    private function hex(mixed $value, string $fallback): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }
}
