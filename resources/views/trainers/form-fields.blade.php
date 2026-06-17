<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $trainer->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $trainer->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input name="email" type="email" value="{{ old('email', $trainer->email) }}" class="crm-field">
        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.phone') }}</span>
        <input name="phone" value="{{ old('phone', $trainer->phone) }}" class="crm-field">
        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<label class="block">
    <span class="crm-label">Bio</span>
    <textarea name="bio" rows="3" class="crm-field">{{ old('bio', $trainer->bio) }}</textarea>
    @error('bio') <span class="crm-help">{{ $message }}</span> @enderror
</label>
<div class="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-center">
    @if ($trainer->photoUrl())
        <img src="{{ $trainer->photoUrl() }}" alt="" class="h-16 w-16 rounded-full object-cover ring-2 ring-slate-100">
    @else
        <span class="flex h-16 w-16 items-center justify-center rounded-full bg-slate-100 text-lg font-semibold text-slate-500">
            {{ mb_substr($trainer->name ?: __('app.trainer'), 0, 1) }}
        </span>
    @endif
    <label class="block">
        <span class="crm-label">{{ __('app.photo') }}</span>
        <input name="photo" type="file" accept="image/png,image/jpeg,image/webp" class="crm-field">
        @error('photo') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<label class="flex items-center gap-3 text-sm font-medium text-slate-700">
    <input type="hidden" name="is_active" value="0">
    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $trainer->is_active)) class="crm-checkbox">
    {{ __('app.active') }}
</label>

<section class="rounded-lg border border-slate-200 bg-slate-50 p-4">
    <label class="flex items-center gap-3 text-sm font-semibold text-slate-800">
        <input type="hidden" name="create_login" value="0">
        <input name="create_login" type="checkbox" value="1" @checked(old('create_login', $trainer->user_id !== null)) class="crm-checkbox">
        {{ __('app.enable_staff_login') }}
    </label>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <label class="block">
            <span class="crm-label">{{ __('app.login_email') }}</span>
            <input name="user_email" type="email" value="{{ old('user_email', $trainer->user?->email ?? $trainer->email) }}" class="crm-field">
            @error('user_email') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
        <label class="block">
            <span class="crm-label">{{ __('app.password') }}</span>
            <input name="user_password" type="password" class="crm-field">
            @error('user_password') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    </div>

    <label class="mt-4 block">
        <span class="crm-label">{{ __('app.role') }}</span>
        <select name="role" class="crm-field">
            @foreach ($accountRoles as $accountRole)
                <option value="{{ $accountRole->value }}" @selected(old('role', $selectedRole->value) === $accountRole->value)>{{ __($accountRole->labelKey()) }}</option>
            @endforeach
        </select>
        @error('role') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <div class="mt-4">
        <div class="crm-label">{{ __('app.permissions') }}</div>
        <div class="mt-2 grid gap-2 sm:grid-cols-2">
            @foreach ($studioPermissions as $permission)
                <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700">
                    <input
                        name="permissions[]"
                        type="checkbox"
                        value="{{ $permission->value }}"
                        @checked(in_array($permission->value, old('permissions', $selectedPermissions), true))
                        class="crm-checkbox"
                    >
                    {{ __('app.permission_'.$permission->value) }}
                </label>
            @endforeach
        </div>
        @error('permissions') <span class="crm-help">{{ $message }}</span> @enderror
        @error('permissions.*') <span class="crm-help">{{ $message }}</span> @enderror
    </div>
</section>
