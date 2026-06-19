@extends('layouts.app')

@section('title', __('app.customers').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.customers') }}</h1>
            <p class="crm-page-copy">{{ __('app.customers_copy') }}</p>
        </div>
        <x-ui.button :href="route('dashboard.accounts.customers.create', $account)">
            <x-ui.icon name="plus" class="h-4 w-4" />
            {{ __('app.create_customer') }}
        </x-ui.button>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($customers as $customer)
            <div class="crm-row lg:grid-cols-[1fr_150px_auto] lg:items-center">
                <div>
                    <h2 class="font-semibold text-slate-950">{{ $customer->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $customer->phone ?? $customer->email ?? __('app.no_contact') }}</p>
                </div>
                <div class="text-sm font-medium text-slate-500">{{ $customer->class_bookings_count }} {{ __('app.bookings') }}</div>
                <div class="flex flex-wrap gap-2 lg:justify-end">
                    <x-ui.button :href="route('dashboard.accounts.customers.edit', [$account, $customer])" variant="secondary" size="sm">{{ __('app.edit') }}</x-ui.button>
                    <form method="POST" action="{{ route('dashboard.accounts.customers.destroy', [$account, $customer]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.button type="submit" variant="danger" size="sm">{{ __('app.delete') }}</x-ui.button>
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_customers')" icon="accounts" class="m-5" />
        @endforelse
    </x-ui.panel>
@endsection
