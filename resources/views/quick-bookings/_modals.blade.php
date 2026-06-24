@php
    $quickBookingPrefill = $quickBookingPrefill ?? [];
    $defaultTimezone = $account->timezone ?? config('app.timezone');
    $defaultDate = now($defaultTimezone)->toDateString();
    $singleLocation = $quickBookingLocations->count() === 1 ? $quickBookingLocations->first() : null;
@endphp

@foreach ($quickBookingOptions as $quickBookingOption)
    @php
        $quickBookingKind = $quickBookingOption['kind'];
        $quickBookingDefinition = $quickBookingOption['definition'];
        $isGroupQuickBooking = $quickBookingKind === \App\Enums\ScheduleKind::GroupClass;
        $modalId = 'quick-booking-title-'.$quickBookingKind->value;
    @endphp

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $modalId }}"
        data-quick-booking-modal="{{ $quickBookingKind->value }}"
    >
        <div class="flex h-[90vh] max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
                <div class="flex items-start gap-4">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-violet-crm-100 text-brand-700">
                        <x-ui.icon :name="$quickBookingDefinition['icon']" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 id="{{ $modalId }}" class="text-lg font-semibold text-slate-950">{{ __('app.add_'.$quickBookingKind->value.'_booking') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.quick_booking_copy') }}</p>
                    </div>
                </div>
                <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-quick-booking-close />
            </div>

            @if (! $isGroupQuickBooking && $quickBookingOption['classTypes']->isEmpty())
                <div class="overflow-y-auto p-5">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        {{ __('app.'.$quickBookingDefinition['empty_key']) }}
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" data-quick-booking-close>{{ __('app.cancel') }}</x-ui.button>
                        @can('manageStudioSettings', $account)
                            <x-ui.button :href="route(\App\Support\ScheduleKindRegistry::routeName($quickBookingKind, 'create'), $account)">
                                <x-ui.icon name="plus" class="h-4 w-4" />
                                {{ __('app.'.$quickBookingDefinition['create_key']) }}
                            </x-ui.button>
                        @endcan
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('dashboard.accounts.quick-bookings.store', $account) }}" class="flex min-h-0 flex-1 flex-col">
                    @csrf
                    <input type="hidden" name="schedule_kind" value="{{ $quickBookingKind->value }}">
                    <input type="hidden" name="website_lead_id" value="{{ $quickBookingPrefill['website_lead_id'] ?? '' }}" data-quick-booking-lead-id>
                    @unless ($isGroupQuickBooking)
                        <input type="hidden" name="starts_at" value="" data-manual-booking-starts-at>
                    @endunless

                    <div class="min-h-0 flex-1 space-y-6 overflow-y-auto p-5">
                        <section class="rounded-lg border border-stone-200 bg-white p-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('app.booking_section') }}</h3>

                            @if ($isGroupQuickBooking)
                                <label class="mt-4 block">
                                    <span class="crm-label">{{ __('app.date') }}</span>
                                    <input
                                        name="group_date"
                                        type="date"
                                        value="{{ old('group_date', $defaultDate) }}"
                                        class="crm-field"
                                        data-group-class-date
                                        data-availability-url="{{ $groupAvailabilityUrl }}"
                                        data-loading="{{ __('app.loading') }}"
                                        data-empty="{{ __('app.no_available_group_classes') }}"
                                    >
                                </label>
                                <div class="mt-4 max-h-64 space-y-2 overflow-y-auto rounded-lg border border-stone-200 bg-slate-50 p-2" data-group-class-results>
                                    <p class="px-2 py-3 text-sm text-slate-500">{{ __('app.choose_date_for_group_classes') }}</p>
                                </div>
                                @error('scheduled_class_id') <span class="crm-help">{{ $message }}</span> @enderror
                            @else
                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    @if ($singleLocation)
                                        <input type="hidden" name="location_id" value="{{ $singleLocation->id }}" data-quick-booking-location>
                                    @else
                                        <label class="block">
                                            <span class="crm-label">{{ __('app.location') }}</span>
                                            <select name="location_id" required class="crm-field" data-quick-booking-location>
                                                @foreach ($quickBookingLocations as $location)
                                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif

                                    <label class="block">
                                        <span class="crm-label">{{ __('app.room') }}</span>
                                        <select name="room_id" required class="crm-field" data-quick-booking-room>
                                            @foreach ($quickBookingRooms as $room)
                                                <option value="{{ $room->id }}" data-location-id="{{ $room->location_id }}">{{ $room->location?->name }} · {{ $room->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.class_type') }}</span>
                                        <select name="class_type_id" required class="crm-field" data-manual-booking-class-type>
                                            @foreach ($quickBookingOption['classTypes'] as $classType)
                                                <option value="{{ $classType->id }}">{{ $classType->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @if ($quickBookingKind === \App\Enums\ScheduleKind::PrivateLesson)
                                        <label class="block">
                                            <span class="crm-label">{{ __('app.trainer') }}</span>
                                            <select name="trainer_id" required class="crm-field" data-manual-booking-trainer>
                                                <option value="">{{ __('app.trainer_not_assigned') }}</option>
                                                @foreach ($quickBookingTrainers as $trainer)
                                                    <option value="{{ $trainer->id }}">{{ $trainer->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.date') }}</span>
                                        <input
                                            type="date"
                                            value="{{ old('manual_date', $defaultDate) }}"
                                            required
                                            class="crm-field"
                                            data-manual-booking-date
                                            data-availability-url="{{ $manualAvailabilityUrl }}"
                                            data-loading="{{ __('app.loading') }}"
                                            data-empty="{{ __('app.no_available_manual_slots') }}"
                                            data-closed="{{ __('app.studio_closed_on_date') }}"
                                        >
                                    </label>
                                    <label class="block">
                                        <span class="crm-label">{{ __('app.start_time') }}</span>
                                        <input type="time" required class="crm-field" data-manual-booking-time>
                                    </label>
                                </div>

                                <div class="mt-4 max-h-52 space-y-2 overflow-y-auto rounded-lg border border-stone-200 bg-slate-50 p-2" data-manual-booking-results data-empty="{{ __('app.no_available_manual_slots') }}" data-closed="{{ __('app.studio_closed_on_date') }}">
                                    <p class="px-2 py-3 text-sm text-slate-500">{{ __('app.choose_date_for_manual_slots') }}</p>
                                </div>
                                @error('starts_at') <span class="crm-help">{{ $message }}</span> @enderror
                            @endif
                        </section>

                        <section class="rounded-lg border border-stone-200 bg-white p-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ __('app.customer_section') }}</h3>

                            <div
                                class="relative mt-4"
                                data-customer-autocomplete
                                data-search-url="{{ $customerSearchUrl }}"
                                data-no-results="{{ __('app.no_customers_found') }}"
                            >
                                <label class="block">
                                    <span class="crm-label">{{ __('app.search_customer') }}</span>
                                    <input
                                        type="text"
                                        class="crm-field"
                                        autocomplete="off"
                                        placeholder="{{ __('app.customer_search_placeholder') }}"
                                        data-customer-autocomplete-input
                                        data-name-target="[data-quick-booking-customer-name]"
                                        data-phone-target="[data-quick-booking-customer-phone]"
                                    >
                                </label>
                                <input type="hidden" name="customer_id" data-customer-autocomplete-id>
                                <div class="absolute z-20 mt-1 hidden max-h-64 w-full overflow-y-auto rounded-lg border border-stone-200 bg-white py-1 shadow-lg" data-customer-autocomplete-results></div>
                            </div>

                            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="crm-label">{{ __('app.phone') }}</span>
                                    <input
                                        name="customer_phone"
                                        type="tel"
                                        value="{{ $quickBookingPrefill['phone'] ?? '' }}"
                                        class="crm-field"
                                        data-phone-mask
                                        data-country-code="{{ $account->country_code ?? 'UA' }}"
                                        data-quick-booking-customer-phone
                                    >
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.person_name') }}</span>
                                    <input name="customer_name" value="{{ $quickBookingPrefill['name'] ?? '' }}" class="crm-field" data-quick-booking-customer-name>
                                </label>
                            </div>

                            <label class="mt-4 block">
                                <span class="crm-label">{{ __('app.notes') }}</span>
                                <input name="notes" class="crm-field">
                            </label>
                        </section>
                    </div>

                    <div class="flex shrink-0 justify-end gap-2 border-t border-stone-200 bg-white p-5">
                        <x-ui.button type="button" variant="secondary" data-quick-booking-close>{{ __('app.cancel') }}</x-ui.button>
                        <x-ui.button type="submit">
                            <x-ui.icon name="plus" class="h-4 w-4" />
                            {{ __('app.add_booking') }}
                        </x-ui.button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endforeach
