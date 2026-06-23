@extends('layouts.public')

@section('title', __('app.profile').' - '.$account->name)

@section('content')
    <main class="min-h-screen bg-canvas px-5 py-8">
        <section class="mx-auto max-w-2xl">
            <div class="flex items-center gap-4">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl border border-stone-200 bg-white shadow-xs">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
                </span>
                <div>
                    <div class="text-sm font-semibold uppercase text-brand-600">{{ $account->name }}</div>
                    <h1 class="text-2xl font-semibold text-slate-950">{{ __('app.profile') }}</h1>
                </div>
            </div>

            @unless ($customer->profileIsComplete())
                <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                    {{ __('app.customer_profile_required') }}
                </div>
            @endunless

            @if (session('status'))
                <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('customer.profile.update', $account->slug) }}" class="mt-6 space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-crm">
                @csrf
                @method('PUT')

                <label class="block">
                    <span class="crm-label">{{ __('app.full_name') }}</span>
                    <input name="name" value="{{ old('name', $customer->name) }}" required class="crm-field">
                    @error('name') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.phone') }}</span>
                    <input
                        name="phone"
                        type="tel"
                        value="{{ old('phone', $customer->phone) }}"
                        required
                        class="crm-field"
                        data-phone-mask
                        data-country-code="{{ $account->country_code ?? 'UA' }}"
                    >
                    @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.email') }}</span>
                    <input name="email" type="email" value="{{ old('email', $customer->email) }}" class="crm-field">
                    @error('email') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.new_password') }}</span>
                        <input name="password" type="password" autocomplete="new-password" class="crm-field">
                        <span class="mt-1.5 block text-sm text-slate-500">{{ __('app.customer_profile_password_help') }}</span>
                        @error('password') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="crm-label">{{ __('app.confirm_new_password') }}</span>
                        <input name="password_confirmation" type="password" autocomplete="new-password" class="crm-field">
                        @error('password_confirmation') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="flex flex-wrap gap-3">
                    <x-ui.button type="submit">
                        {{ __('app.save') }}
                    </x-ui.button>
                    @if ($customer->profileIsComplete())
                        <x-ui.button :href="route('customer.dashboard', $account->slug)" variant="secondary">
                            {{ __('app.customer_portal') }}
                        </x-ui.button>
                    @endif
                </div>
            </form>
        </section>
    </main>
@endsection
