@extends('layouts.app')

@section('title', __('app.event_issue_tickets').' - '.$event->title)

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <x-ui.page-header :title="__('app.event_issue_tickets')" :copy="__('app.event_issue_tickets_help')" />

    <x-ui.event-navigation :account="$account" :event="$event" active="tickets" />

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

    <form method="POST" action="{{ route('dashboard.accounts.events.tickets.issue.store', [$account, $event]) }}" class="space-y-6">
        @csrf

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.event_ticket_selection') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.event_manual_limits_help') }}</p>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-[minmax(0,1fr)_10rem]">
                <label class="block">
                    <span class="crm-label">{{ __('app.event_ticket_option') }}</span>
                    <select name="ticket_type_id" required class="crm-field">
                        <option value="">{{ __('app.select') }}</option>
                        @foreach ($ticketTypes as $ticketType)
                            <option value="{{ $ticketType->id }}" @selected((int) old('ticket_type_id') === $ticketType->id)>
                                {{ $ticketType->name }} · {{ \App\Support\MoneyFormatter::format($ticketType->price_cents, $event->currency) }} · {{ __('app.event_ticket_remaining_count', ['count' => $ticketType->remainingQuantity()]) }}
                            </option>
                        @endforeach
                    </select>
                    @error('ticket_type_id') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.event_ticket_quantity') }}</span>
                    <input type="number" name="quantity" required min="1" max="1000000" value="{{ old('quantity', 1) }}" class="crm-field" inputmode="numeric">
                    @error('quantity') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.event_guest_details') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.event_guest_details_help') }}</p>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="crm-label">{{ __('app.person_name') }}</span>
                    <input name="buyer_name" required maxlength="255" value="{{ old('buyer_name') }}" class="crm-field" autocomplete="name">
                    @error('buyer_name') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.email') }} · {{ __('app.optional') }}</span>
                    <input type="email" name="buyer_email" maxlength="255" value="{{ old('buyer_email') }}" class="crm-field" autocomplete="email">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.event_manual_email_help') }}</span>
                    @error('buyer_email') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.phone') }} · {{ __('app.optional') }}</span>
                    <input name="buyer_phone" maxlength="50" value="{{ old('buyer_phone') }}" class="crm-field" autocomplete="tel">
                    @error('buyer_phone') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            <fieldset>
                <legend class="text-xl font-semibold text-slate-950">{{ __('app.event_manual_payment_kind') }}</legend>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.event_manual_payment_help') }}</p>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach (['paid', 'complimentary'] as $paymentKind)
                        <label class="cursor-pointer rounded-xl border border-stone-200 p-4 has-checked:border-brand-600 has-checked:bg-brand-50 has-checked:ring-1 has-checked:ring-brand-600">
                            <span class="flex items-start gap-3">
                                <input type="radio" name="payment_kind" value="{{ $paymentKind }}" @checked(old('payment_kind', 'paid') === $paymentKind) class="crm-radio mt-1">
                                <span>
                                    <span class="block font-semibold text-slate-950">{{ __('app.event_manual_payment_'.$paymentKind) }}</span>
                                    <span class="mt-1 block text-sm text-slate-500">{{ __('app.event_manual_payment_'.$paymentKind.'_help') }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('payment_kind') <span class="crm-help">{{ $message }}</span> @enderror
            </fieldset>

            <label class="mt-5 block sm:max-w-md">
                <span class="crm-label">{{ __('app.payment_method') }}</span>
                <select name="payment_method" class="crm-field">
                    @foreach ($paymentMethods as $paymentMethod)
                        <option value="{{ $paymentMethod }}" @selected(old('payment_method', 'cash') === $paymentMethod)>{{ __('app.event_manual_method_'.$paymentMethod) }}</option>
                    @endforeach
                </select>
                @error('payment_method') <span class="crm-help">{{ $message }}</span> @enderror
            </label>
        </section>

        <div class="flex flex-wrap justify-end gap-2 rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
            <x-ui.button :href="route('dashboard.accounts.events.tickets.index', [$account, $event])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            <x-ui.button type="submit">
                <x-ui.icon name="ticket" class="h-4 w-4" />
                {{ __('app.event_issue_tickets') }}
            </x-ui.button>
        </div>
    </form>
</div>
@endsection
