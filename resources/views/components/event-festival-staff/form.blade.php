@props([
    'account',
    'membership',
    'user',
])

@php($isEditing = $membership->exists)

<form
    method="POST"
    action="{{ $isEditing
        ? route('dashboard.accounts.event-festival-staff.update', [$account, $membership])
        : route('dashboard.accounts.event-festival-staff.store', $account) }}"
    class="space-y-5"
>
    @csrf
    @if ($isEditing)
        @method('PUT')
    @endif

    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input
            name="name"
            value="{{ old('name', $user->name) }}"
            maxlength="255"
            required
            @if (! $isEditing) autofocus @endif
            autocomplete="name"
            class="crm-field"
        >
        <x-ui.field-error name="name" />
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input
            name="email"
            type="email"
            value="{{ old('email', $user->email) }}"
            maxlength="255"
            required
            autocomplete="email"
            class="crm-field"
        >
        <x-ui.field-error name="email" />
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.password') }}</span>
        <input
            name="password"
            type="password"
            @if (! $isEditing) required @endif
            autocomplete="new-password"
            class="crm-field"
        >
        <span class="mt-1 block text-xs leading-5 text-slate-500">
            {{ $isEditing
                ? __('app.event_festival_staff_password_edit_help')
                : __('app.event_festival_staff_password_create_help') }}
        </span>
        <x-ui.field-error name="password" />
    </label>

    <label class="block">
        <span class="crm-label">{{ __('app.password_confirmation') }}</span>
        <input
            name="password_confirmation"
            type="password"
            @if (! $isEditing) required @endif
            autocomplete="new-password"
            class="crm-field"
        >
        <x-ui.field-error name="password_confirmation" />
    </label>

    <div class="flex flex-wrap gap-2">
        <x-ui.button type="submit">
            <x-ui.icon :name="$isEditing ? 'save' : 'plus'" class="h-4 w-4" />
            {{ $isEditing ? __('app.save') : __('app.add_event_festival_staff') }}
        </x-ui.button>
        <x-ui.button
            :href="route('dashboard.accounts.event-festival-staff.index', $account)"
            variant="secondary"
        >
            {{ __('app.cancel') }}
        </x-ui.button>
    </div>
</form>
