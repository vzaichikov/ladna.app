@extends('layouts.public')

@section('title', __('app.customer_events_and_festivals').' - '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer :account="$account" :show-locale-switcher="true" class="mx-auto max-w-6xl bg-canvas px-5 pb-8 sm:px-8" />
@endsection

@section('content')
    <main class="min-h-[calc(100vh-8rem)] bg-canvas px-5 py-8">
        <section class="mx-auto max-w-6xl">
            <x-customer-auth.header :account="$account" :customer="$customer" :title="__('app.customer_events_and_festivals')" />

            @if ($events->isEmpty() && $festivals->isEmpty())
                <x-ui.empty-state :title="__('app.customer_events_and_festivals_empty')" icon="calendar" class="mt-6 bg-white" />
            @else
                <x-ui.studio-public-listings :account="$account" :events="$events" :festivals="$festivals" />
            @endif
        </section>
    </main>
@endsection
