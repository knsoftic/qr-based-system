@php
    use App\Models\User;
    use App\Support\Permissions;
    $current = old('permissions', $user->permissions ?? []);
    $defaults = [
        'super_admin' => Permissions::defaultsFor('super_admin'),
        'manager' => Permissions::defaultsFor('manager'),
        'agent' => Permissions::defaultsFor('agent'),
    ];
@endphp
@csrf
<div class="grid grid-2">
    <div class="form-row">
        <label for="name">Name</label>
        <input id="name" type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="100">
    </div>
    <div class="form-row">
        <label for="email">Email (login)</label>
        <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="150">
    </div>
</div>

<div class="grid grid-2">
    <div class="form-row">
        <label for="role">Role</label>
        <select id="role" name="role" required>
            @foreach (User::ROLES as $key => $label)
                <option value="{{ $key }}" @selected(old('role', $user->role) === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <div class="hint">Super Admin has full access. Manager / Agent are limited to the permissions ticked below.</div>
    </div>
    <div class="form-row" data-staff-only>
        <label for="parent_id">Supervisor (optional)</label>
        <select id="parent_id" name="parent_id">
            <option value="">— None —</option>
            @foreach ($managers as $m)
                <option value="{{ $m->id }}" @selected((string) old('parent_id', $user->parent_id) === (string) $m->id)>{{ $m->name }} ({{ $m->roleLabel() }})</option>
            @endforeach
        </select>
    </div>
</div>

<div class="grid grid-2">
    <div class="form-row">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="new-password" @required(! $user->exists)>
        <div class="hint">@if ($user->exists)Leave blank to keep the current password. @endif At least 10 characters, letters and numbers.</div>
    </div>
    <div class="form-row">
        <label for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" @required(! $user->exists)>
    </div>
</div>

<fieldset class="fieldset" data-staff-only>
    <legend>Pay</legend>
    <div class="grid grid-3">
        <div class="form-row">
            <label for="salary">Monthly salary (PKR)</label>
            <input id="salary" type="number" name="salary" value="{{ old('salary', $user->salary) }}" min="0" step="0.01" placeholder="None">
        </div>
        <div class="form-row">
            <label for="payin_commission">Pay-In commission (%)</label>
            <input id="payin_commission" type="number" name="payin_commission" value="{{ old('payin_commission', $user->payin_commission) }}" min="0" max="100" step="0.01" placeholder="0">
        </div>
        <div class="form-row">
            <label for="payout_commission">Pay-Out commission (%)</label>
            <input id="payout_commission" type="number" name="payout_commission" value="{{ old('payout_commission', $user->payout_commission) }}" min="0" max="100" step="0.01" placeholder="0">
        </div>
    </div>
    <p class="hint mt-0">Set a monthly salary, a commission, or both. Commissions apply to the person's own merchants (used in settlement — coming next).</p>
</fieldset>

<fieldset class="fieldset" data-staff-only id="permissions-box">
    <legend>Permissions</legend>
    <p class="hint mt-0">What this person can do in the panel. <button type="button" class="btn btn--sm" id="apply-defaults">Apply role defaults</button></p>
    <div class="perm-groups">
        @foreach (Permissions::GROUPS as $group => $perms)
            <div class="perm-group">
                <div class="perm-group-title">{{ $group }}</div>
                @foreach ($perms as $key => $label)
                    <label class="checkbox"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key, $current, true))> {{ $label }}</label>
                @endforeach
            </div>
        @endforeach
    </div>
</fieldset>

<script id="role-defaults" type="application/json">@json($defaults)</script>
<script>
(function () {
    var role = document.getElementById('role');
    var box = document.getElementById('permissions-box');
    var defaults = {};
    try { defaults = JSON.parse(document.getElementById('role-defaults').textContent); } catch (e) {}

    function sync() {
        var isSuper = role.value === 'super_admin';
        document.querySelectorAll('[data-staff-only]').forEach(function (el) { el.style.display = isSuper ? 'none' : ''; });
    }
    function applyDefaults() {
        var want = defaults[role.value] || [];
        box.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = want.indexOf(cb.value) !== -1; });
    }
    role.addEventListener('change', function () {
        sync();
        var any = [...box.querySelectorAll('input[type="checkbox"]')].some(function (cb) { return cb.checked; });
        if (!any) { applyDefaults(); }
    });
    document.getElementById('apply-defaults').addEventListener('click', applyDefaults);
    sync();
})();
</script>
