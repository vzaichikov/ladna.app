@extends('layouts.app')

@section('title', $account->name.' - '.__('app.platform'))

@section('content')
    <x-ui.panel padding="lg">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-center gap-4">
                <span class="flex h-16 w-16 items-center justify-center rounded-xl bg-brand-50">
                    <img src="{{ $account->logoUrl() }}" alt="" class="max-h-11 max-w-11 object-contain">
                </span>
                <div>
                    <div class="crm-page-kicker">{{ __('app.platform') }}</div>
                    <h1 class="crm-page-title">{{ $account->name }}</h1>
                    <p class="crm-page-copy">{{ $account->slug }} · {{ __('app.'.$account->status->value) }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button :href="route('platform.accounts.edit', $account)">
                    <x-ui.icon name="edit" class="h-4 w-4" />
                    {{ __('app.edit') }}
                </x-ui.button>
                <x-ui.button :href="route('platform.accounts.customer-auth.edit', $account)" variant="secondary">
                    <x-ui.icon name="key-round" class="h-4 w-4" />
                    {{ __('app.customer_auth_settings') }}
                </x-ui.button>
                <form method="POST" action="{{ route('platform.accounts.destroy', $account) }}" data-confirm-delete>
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="trash" class="h-4 w-4" />
                        {{ __('app.delete') }}
                    </x-ui.button>
                </form>
            </div>
        </div>
    </x-ui.panel>

    <section class="mt-6 grid gap-4 md:grid-cols-3">
        <x-ui.metric :label="__('app.locations')" :value="$account->locations->count()" icon="locations" />
        <x-ui.metric :label="__('app.generated_classes')" :value="$account->scheduled_classes_count" icon="generated-classes" accent="brand" />
        <x-ui.metric :label="__('app.subscription_plan')" :value="$account->subscription?->plan?->name ?? '-'" icon="platform" accent="emerald" />
    </section>
@endsection
