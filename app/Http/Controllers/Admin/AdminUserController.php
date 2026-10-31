<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Staff, roles and permissions. Super admins have full access; managers and
 * agents get a per-user permission set, an optional supervisor and their pay.
 */
class AdminUserController extends Controller
{
    public function index(): View
    {
        return view('admin.admins.index', [
            'users' => User::query()->with('parent')->orderBy('role')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.admins.create', [
            'user' => new User(['role' => User::ROLE_AGENT, 'is_active' => true]),
            'managers' => $this->managers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['email'] = strtolower($request->validate(['email' => ['required', 'email', 'max:150', 'unique:users,email']])['email']);
        $request->validate(['password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]]);
        $data['password'] = $request->input('password');
        $data['is_active'] = true;

        $user = User::query()->create($data);

        Audit::log('staff.created', $user, [], ['name' => $user->name, 'email' => $user->email, 'role' => $user->role]);

        return redirect()->route('admin.admins.index')->with('success', "{$user->roleLabel()} \"{$user->name}\" created.");
    }

    public function edit(User $user): View
    {
        return view('admin.admins.edit', [
            'user' => $user,
            'managers' => $this->managers()->reject(fn ($m) => $m->is($user)),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot change your own role or permissions.');
        }

        $data = $this->validated($request);
        $data['email'] = strtolower($request->validate([
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
        ])['email']);

        if ($request->filled('password')) {
            $request->validate(['password' => ['confirmed', Password::min(10)->letters()->numbers()]]);
            $data['password'] = $request->input('password');
        }

        $user->fill($data)->save();

        Audit::log('staff.updated', $user, ...Audit::changes($user));

        return redirect()->route('admin.admins.index')->with('success', "\"{$user->name}\" updated.");
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot disable your own account.');
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        Audit::log($user->is_active ? 'staff.enabled' : 'staff.disabled', $user);

        return back()->with('success', $user->is_active ? 'Account enabled.' : 'Account disabled.');
    }

    /**
     * Common validation and normalisation for create / update.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'parent_id' => ['nullable', 'integer', 'exists:users,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(Permissions::all())],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'payin_commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payout_commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $role = $data['role'];

        return [
            'name' => $data['name'],
            'role' => $role,
            // A super admin bypasses the list, so store nothing for them.
            'permissions' => $role === User::ROLE_SUPER ? [] : array_values(array_intersect($data['permissions'] ?? [], Permissions::all())),
            'parent_id' => $role === User::ROLE_SUPER ? null : ($data['parent_id'] ?? null),
            'salary' => $data['salary'] ?? null,
            'payin_commission' => $data['payin_commission'] ?? null,
            'payout_commission' => $data['payout_commission'] ?? null,
        ];
    }

    private function managers()
    {
        return User::query()
            ->whereIn('role', [User::ROLE_SUPER, User::ROLE_MANAGER])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
