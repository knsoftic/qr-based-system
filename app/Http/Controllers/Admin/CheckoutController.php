<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CheckoutTheme;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin editor for the customer checkout page: branding (logo, colours, font),
 * Urdu/RTL text, Telegram link, a custom button, and the allowed mobile codes.
 */
class CheckoutController extends Controller
{
    private const TEXT_KEYS = [
        'language', 'font', 'primary_color', 'accent_color',
        'tagline', 'step1_title', 'step1_desc', 'step1_button', 'step2_hint', 'steps',
        'paid_title', 'paid_text', 'expired_title', 'expired_text', 'failed_title', 'failed_text',
        'footer', 'invalid_code_msg', 'telegram', 'telegram_label', 'button_label', 'button_url',
    ];

    private const FONT_EXT = ['ttf', 'otf', 'woff', 'woff2'];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function edit(): View
    {
        return view('admin.checkout.edit', [
            'settings' => $this->settings->all(),
            'theme' => new CheckoutTheme($this->settings),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'language' => ['required', Rule::in(['ur', 'en'])],
            'font' => ['required', Rule::in(['nastaliq', 'system'])],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'step1_title' => ['nullable', 'string', 'max:300'],
            'step1_desc' => ['nullable', 'string', 'max:1000'],
            'step1_button' => ['nullable', 'string', 'max:100'],
            'step2_hint' => ['nullable', 'string', 'max:300'],
            'steps' => ['nullable', 'string', 'max:2000'],
            'paid_title' => ['nullable', 'string', 'max:200'],
            'paid_text' => ['nullable', 'string', 'max:1000'],
            'expired_title' => ['nullable', 'string', 'max:200'],
            'expired_text' => ['nullable', 'string', 'max:1000'],
            'failed_title' => ['nullable', 'string', 'max:200'],
            'failed_text' => ['nullable', 'string', 'max:1000'],
            'footer' => ['nullable', 'string', 'max:200'],
            'invalid_code_msg' => ['nullable', 'string', 'max:300'],
            'telegram' => ['nullable', 'string', 'max:200'],
            'telegram_label' => ['nullable', 'string', 'max:100'],
            'button_label' => ['nullable', 'string', 'max:100'],
            'button_url' => ['nullable', 'url:http,https', 'max:300'],
            'codes_json' => ['nullable', 'string', 'max:20000'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'font_file' => ['nullable', 'file', 'max:5120', function (string $attr, mixed $value, \Closure $fail) {
                if ($value instanceof UploadedFile && ! in_array(strtolower($value->getClientOriginalExtension()), self::FONT_EXT, true)) {
                    $fail('The font must be a .ttf, .otf, .woff or .woff2 file.');
                }
            }],
        ]);

        $values = [];
        foreach (self::TEXT_KEYS as $key) {
            $values['checkout.'.$key] = $data[$key] ?? null;
        }
        $values['checkout.codes'] = $this->parseCodes($request->input('codes_json'));

        if ($request->boolean('remove_logo')) {
            $this->deleteFile($this->settings->get('checkout.logo_path'));
            $values['checkout.logo_path'] = null;
        }
        if ($request->hasFile('logo')) {
            $this->deleteFile($this->settings->get('checkout.logo_path'));
            $values['checkout.logo_path'] = $this->storeUpload($request->file('logo'));
        }

        if ($request->boolean('remove_font')) {
            $this->deleteFile($this->settings->get('checkout.font_path'));
            $values['checkout.font_path'] = null;
        }
        if ($request->hasFile('font_file')) {
            $this->deleteFile($this->settings->get('checkout.font_path'));
            $values['checkout.font_path'] = $this->storeUpload($request->file('font_file'));
        }

        $this->settings->set($values);

        Audit::log('settings.checkout_updated', null, [], ['keys' => array_keys($values)]);

        return redirect()->route('admin.checkout.edit')->with('success', 'Checkout page settings saved.');
    }

    /**
     * @return list<array{label: string, prefixes: string, enabled: bool}>
     */
    private function parseCodes(?string $json): array
    {
        $rows = json_decode((string) $json, true);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $prefixes = [];
            foreach (preg_split('/[,\s]+/', (string) ($row['prefixes'] ?? '')) as $p) {
                $p = preg_replace('/\D/', '', $p);
                if (strlen($p) === 4 && str_starts_with($p, '03')) {
                    $prefixes[$p] = $p;
                }
            }
            if ($prefixes === []) {
                continue;
            }
            $out[] = [
                'label' => Str::limit(trim((string) ($row['label'] ?? '')), 60, ''),
                'prefixes' => implode(',', array_values($prefixes)),
                'enabled' => (bool) ($row['enabled'] ?? false),
            ];
        }

        return $out;
    }

    private function storeUpload(UploadedFile $file): string
    {
        $dir = public_path('branding');
        File::ensureDirectoryExists($dir);

        $name = Str::random(16).'.'.strtolower($file->getClientOriginalExtension());
        $file->move($dir, $name);

        return $name;
    }

    private function deleteFile(?string $name): void
    {
        if ($name && File::exists(public_path('branding/'.$name))) {
            File::delete(public_path('branding/'.$name));
        }
    }
}
