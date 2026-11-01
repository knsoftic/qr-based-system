<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Telegram\LinkService;
use App\Telegram\TelegramClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('admin.profile.edit');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();

        // Sign out every other session that used the old password.
        Auth::logoutOtherDevices($data['password']);

        Audit::log('admin.password_changed', $request->user());

        return back()->with('success', 'Password updated.');
    }

    /** Start the "Connect Telegram" handshake for the signed-in staff member. */
    public function connectTelegram(Request $request, LinkService $links, TelegramClient $client): RedirectResponse
    {
        if (! $client->botUsername()) {
            return redirect()->to(route('admin.profile.edit').'#telegram')
                ->with('error', 'Telegram is not set up yet (no bot token / username).');
        }

        $token = $links->createToken($request->user());
        Audit::log('admin.telegram_connect_started', $request->user());

        return redirect()->to(route('admin.profile.edit').'#telegram')
            ->with('telegram_link', $links->deepLink($token));
    }

    public function disconnectTelegram(Request $request, LinkService $links): RedirectResponse
    {
        $links->unbind($request->user());
        Audit::log('admin.telegram_disconnected', $request->user());

        return redirect()->to(route('admin.profile.edit').'#telegram')->with('success', 'Telegram disconnected.');
    }
}
