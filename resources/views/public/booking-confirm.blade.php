@extends('layouts.public')

@section('title', __('app.booking_confirmation').' · '.$account->name)

@section('publicFooter')
    <x-ui.powered-footer class="mx-auto max-w-xl bg-canvas px-4 pb-6 sm:px-6" />
@endsection

@section('content')
    @php
        $customerDisplayName = $customer?->name ?? $customer?->phone ?? $customer?->email;
    @endphp

    <main class="min-h-[calc(100vh-8rem)] bg-canvas text-slate-950">
        <section class="mx-auto max-w-xl px-4 py-4 sm:px-6">
            <a href="{{ $selection['backUrl'] }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                <x-ui.icon name="arrow-left" class="h-4 w-4" />
                {{ __('app.public_booking_back_to_schedule') }}
            </a>

            <header class="mt-3">
                <h1 class="text-2xl font-semibold leading-tight text-slate-950">{{ __('app.booking_confirmation') }}</h1>
            </header>

            <article class="mt-4 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
                <div class="flex items-start gap-3">
                    <div class="flex h-14 w-16 shrink-0 flex-col items-center justify-center rounded-xl bg-ink-950 text-white">
                        <span class="text-lg font-semibold leading-none">{{ explode(' ', $selection['timeLabel'])[0] }}</span>
                        <span class="mt-1 text-[11px] text-slate-300">{{ $selection['durationLabel'] }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold leading-snug text-slate-950">{{ $selection['title'] }}</h2>
                        <div class="mt-2 space-y-1 text-sm text-slate-600">
                            <div>{{ $selection['dateLabel'] }}</div>
                            <div>{{ $selection['timeLabel'] }}</div>
                            <div>{{ $selection['roomLabel'] }}</div>
                            @if ($selection['trainerLabel'])
                                <div>{{ $selection['trainerLabel'] }}</div>
                            @endif
                        </div>
                    </div>
                </div>
            </article>

            <form method="POST" action="{{ route('public.booking.store', [$account->slug, $location->slug]) }}" class="mt-4 space-y-4 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
                @csrf

                @foreach ($selection['hiddenFields'] as $field => $value)
                    @if ($value !== null && $value !== '')
                        <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                    @endif
                @endforeach

                @if ($customer)
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">
                        {{ __('app.public_schedule_logged_in_as', ['name' => $customerDisplayName ?? __('app.customer_section')]) }}
                    </div>
                @elseif ($allowsGuestBooking)
                    <fieldset class="space-y-3">
                        <legend class="text-sm font-semibold text-slate-950">{{ __('app.public_booking_guest_details') }}</legend>
                        <p class="text-sm leading-5 text-slate-500">{{ __('app.public_booking_guest_details_help') }}</p>

                        <label class="block">
                            <span class="crm-label">{{ __('app.person_name') }}</span>
                            <input name="customer_name" type="text" value="{{ old('customer_name') }}" class="crm-field" autocomplete="name" required>
                            @error('customer_name') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="crm-label">{{ __('app.phone') }}</span>
                            <input name="customer_phone" type="tel" value="{{ old('customer_phone') }}" class="crm-field" autocomplete="tel" data-phone-mask data-country-code="{{ $account->country_code ?? 'UA' }}" required>
                            @error('customer_phone') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                    </fieldset>
                @endif

                @if ($selection['scheduleKind'] === \App\Enums\ScheduleKind::PrivateLesson)
                    <label class="block">
                        <span class="crm-label">{{ __('app.people_count') }}</span>
                        <input name="people_count" type="number" min="1" max="999" value="{{ old('people_count', $selection['peopleCount'] ?? 1) }}" class="crm-field" required>
                        <span class="crm-help">{{ __('app.private_lesson_people_count_help') }}</span>
                        @error('people_count') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                @endif

                <label class="block">
                    <span class="crm-label">{{ __('app.notes') }}</span>
                    <textarea name="notes" rows="3" class="crm-field">{{ old('notes') }}</textarea>
                    @error('notes') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                @foreach (['schedule_kind', 'scheduled_class_id', 'date', 'starts_at', 'class_type_id', 'room_id', 'trainer_id'] as $field)
                    @error($field) <span class="crm-help">{{ $message }}</span> @enderror
                @endforeach

                <x-ui.button type="submit" variant="primary" class="w-full">
                    <x-ui.icon name="check" class="h-4 w-4" />
                    {{ __('app.confirm_booking') }}
                </x-ui.button>
            </form>
        </section>
    </main>
@endsection
