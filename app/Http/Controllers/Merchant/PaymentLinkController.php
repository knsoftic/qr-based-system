<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Payments\Qr\QrRenderer;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Merchant portal: create and manage reusable payment links, see how much
 * came through each one, and get a printable QR for the link.
 */
class PaymentLinkController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function index(Request $request): View
    {
        $merchant = $this->merchant($request);
        $paid = fn ($q) => $q->where('status', 'paid');

        return view('merchant.links', [
            'merchant' => $merchant,
            'links' => $merchant->paymentLinks()
                ->withCount(['payments', 'payments as paid_count' => $paid])
                ->withSum(['payments as paid_volume' => $paid], 'amount')
                ->latest('id')
                ->paginate(20),
            'min' => (float) $this->settings->get('payin.min_amount'),
            'max' => (float) $this->settings->get('payin.max_amount'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $merchant = $this->merchant($request);
        $min = (float) $this->settings->get('payin.min_amount');
        $max = (float) $this->settings->get('payin.max_amount');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:'.$min, 'max:'.$max],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        $link = $merchant->paymentLinks()->create([
            'title' => trim(strip_tags($data['title'])),
            'description' => isset($data['description']) ? trim(strip_tags($data['description'])) : null,
            'amount' => $data['amount'] ?? null,
            'is_active' => true,
            // A date means "until the end of that day" in Pakistan time.
            'expires_at' => isset($data['expires_at'])
                ? Carbon::parse($data['expires_at'], 'Asia/Karachi')->endOfDay()->setTimezone(config('app.timezone'))
                : null,
        ]);

        Audit::log('merchant.payment_link_created', $link, [], ['code' => $link->code, 'amount' => $link->amount], byAdmin: false);

        return redirect()->route('merchant.links')->with('success', 'Payment link created: '.$link->url());
    }

    public function toggle(Request $request, int $id): RedirectResponse
    {
        $link = $this->merchant($request)->paymentLinks()->findOrFail($id);
        $link->forceFill(['is_active' => ! $link->is_active])->save();

        Audit::log($link->is_active ? 'merchant.payment_link_enabled' : 'merchant.payment_link_disabled', $link, byAdmin: false);

        return redirect()->route('merchant.links')->with('success', $link->is_active ? 'Link activated.' : 'Link deactivated; customers can no longer pay through it.');
    }

    /** A QR of the link URL, to print at a counter or share as an image. */
    public function qr(Request $request, int $id, QrRenderer $renderer): Response
    {
        $link = $this->merchant($request)->paymentLinks()->findOrFail($id);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($renderer->png($link->url(), 10), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => $disposition.'; filename="payment-link-'.$link->code.'.png"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user('merchant');
    }
}
