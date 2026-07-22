@php
    $customerDisplayName = $customer?->name ?? $customer?->phone ?? $customer?->email;
@endphp

<header class="border-b border-stone-200 pb-3">
    <div class="flex items-start gap-3">
        @if ($account->logo_path)
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-stone-200 bg-white shadow-xs">
                <img src="{{ $account->logoUrl() }}" alt="" class="max-h-8 max-w-8 object-contain">
            </span>
        @endif
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-semibold leading-tight text-slate-950 sm:text-2xl">{{ $location->name }}</h1>
            @if ($location->address)
                <p class="mt-1 text-sm leading-5 text-slate-500">{{ $location->address }}</p>
            @endif
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        @if ($customer)
            <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800">
                <x-ui.icon name="user" class="h-3.5 w-3.5" />
                {{ __('app.public_schedule_logged_in_as', ['name' => $customerDisplayName ?? __('app.customer_section')]) }}
            </span>
            <a href="{{ route('customer.dashboard', $account->slug) }}" class="inline-flex items-center gap-1.5 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs">
                <x-ui.icon name="layout-dashboard" class="h-3.5 w-3.5" />
                {{ __('app.customer_portal') }}
            </a>
        @else
            <a href="{{ route('customer.studio.login', $account->slug) }}" class="inline-flex items-center gap-1.5 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs">
                <x-ui.icon name="log-in" class="h-3.5 w-3.5" />
                {{ __('app.customer_login') }}
            </a>
        @endif

        <x-ui.public-legal-links :account="$account" :return-url="request()->fullUrl()" />

        <form method="POST" action="{{ route('locale.update') }}">
            @csrf
            <select name="locale" onchange="this.form.submit()" class="rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs">
                @foreach (config('ladna.locales') as $locale => $label)
                    <option value="{{ $locale }}" @selected(app()->getLocale() === $locale)>{{ strtoupper($locale) }}</option>
                @endforeach
            </select>
        </form>
    </div>
</header>
