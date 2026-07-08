@extends('layouts.public')

@section('title', __('app.customer_studio_selection'))

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-4xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    @php
        $displayName = $customer->name ?? $customer->phone ?? $customer->email ?? __('app.customer_section');
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8">
        <section class="mx-auto max-w-4xl">
            <div class="flex items-center gap-4">
                <x-ui.app-logo mark-class="h-12 w-12" text-class="text-slate-950" />
            </div>

            <header class="mt-8 rounded-2xl border border-stone-200 bg-white p-6 shadow-crm">
                <div class="inline-flex items-center gap-2 rounded-full border border-stone-200 bg-slate-50 px-3 py-1 text-sm font-semibold text-slate-600">
                    <x-ui.icon name="user" class="h-4 w-4" />
                    {{ __('app.customer_studio_selection_signed_in_as', ['name' => $displayName]) }}
                </div>
                <h1 class="mt-4 text-3xl font-semibold leading-tight text-slate-950">{{ __('app.customer_studio_selection') }}</h1>
                <p class="mt-3 max-w-2xl text-base leading-7 text-slate-500">{{ __('app.customer_studio_selection_copy') }}</p>
            </header>

            <section class="mt-6 grid gap-4 sm:grid-cols-2">
                @forelse ($studioCustomers as $studioCustomer)
                    @php
                        $account = $studioCustomer->account;
                    @endphp

                    @continue(! $account)

                    <article class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm">
                        <div class="flex items-start gap-4">
                            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl border border-stone-200 bg-slate-50 p-2 shadow-xs">
                                <img src="{{ $account->logoUrl() }}" alt="" class="max-h-10 max-w-10 object-contain">
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold text-slate-950">{{ $account->name }}</h2>
                                @if ($account->studio_slogan)
                                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ $account->studio_slogan }}</p>
                                @endif
                                @if ($studioCustomer->is($customer))
                                    <span class="mt-3 inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-800">
                                        {{ __('app.current_studio') }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="mt-5">
                            @if ($studioCustomer->is($customer))
                                <x-ui.button :href="$studioAccess->destinationForCustomer($studioCustomer)" class="w-full">
                                    <x-ui.icon name="dashboard" class="h-4 w-4" />
                                    {{ __('app.open_customer_portal') }}
                                </x-ui.button>
                            @else
                                <form method="POST" action="{{ route('customer.studios.switch', $studioCustomer) }}">
                                    @csrf
                                    <x-ui.button type="submit" variant="secondary" class="w-full">
                                        <x-ui.icon name="log-in" class="h-4 w-4" />
                                        {{ __('app.switch_to_studio') }}
                                    </x-ui.button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state :title="__('app.customer_studio_selection_empty')" icon="locations" class="sm:col-span-2" />
                @endforelse
            </section>
        </section>
    </main>
@endsection
