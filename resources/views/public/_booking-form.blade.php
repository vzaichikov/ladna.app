@php
    $customerDisplayName = $customer?->name ?? $customer?->phone ?? $customer?->email;
    $formAction = $isModal
        ? route('public.booking.store', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'presentation' => 'modal',
        ])
        : route('public.booking.store', [$account->slug, $location->slug]);
@endphp

<form
    method="POST"
    action="{{ $formAction }}"
    class="space-y-4 rounded-xl border border-stone-200 bg-white p-4 shadow-xs"
    @if ($isModal)
        data-async-form
        data-public-booking-form
        novalidate
    @endif
>
    @csrf

    @foreach ($selection['hiddenFields'] as $field => $value)
        @if ($value !== null && $value !== '')
            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
        @endif
    @endforeach

    @if ($returnUrl)
        <input type="hidden" name="return_to" value="{{ $returnUrl }}">
    @endif

    @if ($isModal && $customer)
        <input type="hidden" name="customer_session_expected" value="1">
    @endif

    @if ($isModal)
        <div
            data-async-form-status
            data-error-message="{{ __('app.async_request_failed') }}"
            data-validation-message="{{ __('app.async_validation_failed') }}"
            class="hidden rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
        ></div>
    @endif

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
                <span data-async-error-for="customer_name"></span>
                @error('customer_name') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="crm-label">{{ __('app.phone') }}</span>
                <input
                    name="customer_phone"
                    type="tel"
                    value="{{ old('customer_phone') }}"
                    class="crm-field"
                    autocomplete="tel"
                    data-phone-mask
                    data-country-code="{{ $account->country_code ?? 'UA' }}"
                    @unless ($isModal) data-phone-mask-validate="false" @endunless
                    required
                >
                <span data-async-error-for="customer_phone"></span>
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
        <span data-async-error-for="notes"></span>
        @error('notes') <span class="crm-help">{{ $message }}</span> @enderror
    </label>

    <div data-async-error-for="scheduled_class_id">
        @foreach (['schedule_kind', 'scheduled_class_id', 'date', 'starts_at', 'class_type_id', 'room_id', 'trainer_id'] as $field)
            @error($field) <span class="crm-help">{{ $message }}</span> @enderror
        @endforeach
    </div>

    <x-ui.button type="submit" variant="primary" class="w-full">
        <x-ui.icon name="check" class="h-4 w-4" />
        {{ __('app.confirm_booking') }}
    </x-ui.button>
</form>
