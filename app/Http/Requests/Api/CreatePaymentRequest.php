<?php

namespace App\Http\Requests\Api;

use App\Models\Merchant;
use App\Support\Settings;
use App\Support\UrlGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->merchant() !== null;
    }

    public function merchant(): ?Merchant
    {
        return $this->attributes->get('merchant');
    }

    protected function prepareForValidation(): void
    {
        $clean = fn ($value) => is_string($value) ? trim(strip_tags($value)) : $value;

        $this->merge([
            'description' => $clean($this->input('description')),
            'customer' => is_array($this->input('customer'))
                ? array_map($clean, $this->input('customer'))
                : $this->input('customer'),
        ]);
    }

    public function rules(): array
    {
        $settings = app(Settings::class);

        return [
            'amount' => [
                'required', 'numeric', 'decimal:0,2',
                'min:'.$settings->get('payin.min_amount'),
                'max:'.$settings->get('payin.max_amount'),
            ],
            'order_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-.:#\/]+$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'customer' => ['nullable', 'array:name,email,phone'],
            'customer.name' => ['nullable', 'string', 'max:100'],
            'customer.email' => ['nullable', 'email:rfc', 'max:150'],
            'customer.phone' => ['nullable', 'string', 'regex:/^\+?[0-9 \-]{6,20}$/'],
            'callback_url' => [
                'nullable', 'url:http,https', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! UrlGuard::isAllowed($value)) {
                        $fail('The callback URL must be a public http(s) address.');
                    }
                },
            ],
            'return_url' => ['nullable', 'url:http,https', 'max:255'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
