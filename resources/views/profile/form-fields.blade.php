@php
    $profileInitial = mb_substr($profileUser?->name ?? __('app.app_name'), 0, 1);
@endphp

<div>
    <h2 class="text-lg font-semibold text-slate-950">{{ __('app.account_details') }}</h2>
    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.account_details_copy') }}</p>
</div>

<div class="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-center">
    <div class="flex h-20 w-20 items-center justify-center overflow-hidden rounded-full border border-stone-200 bg-brand-50">
        @if ($profileUser?->avatarUrl())
            <img src="{{ $profileUser->avatarUrl() }}" alt="" class="h-full w-full object-cover">
        @else
            <span class="text-2xl font-semibold text-brand-700">{{ $profileInitial }}</span>
        @endif
    </div>
    <label class="block">
        <span class="crm-label">{{ __('app.avatar') }}</span>
        <input name="avatar" type="file" accept="image/png,image/jpeg,image/webp" class="crm-field">
        <span class="mt-1 block text-xs font-medium text-slate-500">{{ __('app.avatar_help') }}</span>
        @error('avatar') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<label class="block">
    <span class="crm-label">{{ __('app.person_name') }}</span>
    <input name="name" value="{{ old('name', $profileUser?->name) }}" required class="crm-field">
    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input name="email" type="email" value="{{ old('email', $profileUser?->email) }}" required class="crm-field">
        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.phone') }}</span>
        <input name="phone" value="{{ old('phone', $profileUser?->phone) }}" class="crm-field">
        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.new_password') }}</span>
        <input name="password" type="password" autocomplete="new-password" class="crm-field">
        <span class="mt-1 block text-xs font-medium text-slate-500">{{ __('app.password_help') }}</span>
        @error('password') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.confirm_new_password') }}</span>
        <input name="password_confirmation" type="password" autocomplete="new-password" class="crm-field">
    </label>
</div>

<x-ui.button type="submit">
    <x-ui.icon name="edit" class="h-4 w-4" />
    {{ __('app.save') }}
</x-ui.button>
