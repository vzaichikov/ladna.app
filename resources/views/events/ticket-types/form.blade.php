@extends('layouts.app')

@section('title', ($ticketType->exists ? __('app.event_edit_ticket_type') : __('app.event_add_ticket_type')).' - '.$event->title)

@section('content')
<div class="mx-auto max-w-7xl space-y-6" data-event-admin-page>
    <x-ui.page-header
        :title="$ticketType->exists ? __('app.event_edit_ticket_type') : __('app.event_add_ticket_type')"
        :copy="__('app.event_ticket_type_form_help', ['event' => $event->title])"
    />

    <x-ui.event-navigation :account="$account" :event="$event" active="ticket-types" />

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
            <p class="font-semibold">{{ __('app.event_form_errors') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form
        method="POST"
        action="{{ $ticketType->exists
            ? route('dashboard.accounts.events.ticket-types.update', [$account, $event, $ticketType])
            : route('dashboard.accounts.events.ticket-types.store', [$account, $event]) }}"
        class="space-y-6"
    >
        @csrf
        @if ($ticketType->exists)
            @method('PUT')
        @endif

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <x-ui.event-ticket-type-fields :event="$event" :ticket-type="$ticketType" />
        </section>

        <div class="flex flex-wrap justify-end gap-2 rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
            <x-ui.button :href="route('dashboard.accounts.events.ticket-types.index', [$account, $event])" variant="secondary">
                {{ __('app.cancel') }}
            </x-ui.button>
            <x-ui.button type="submit">
                <x-ui.icon name="save" class="h-4 w-4" />
                {{ $ticketType->exists ? __('app.save') : __('app.event_add_ticket_type') }}
            </x-ui.button>
        </div>
    </form>
</div>
@endsection
