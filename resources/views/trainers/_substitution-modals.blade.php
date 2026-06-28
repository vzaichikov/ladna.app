@php
    $singleLocation = $substitutionLocations->count() === 1 ? $substitutionLocations->first() : null;
@endphp

<div
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="trainer-substitution-classes-title"
    data-trainer-substitution-modal="classes"
>
    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
            <div>
                <h2 id="trainer-substitution-classes-title" class="text-lg font-semibold text-slate-950" data-trainer-substitution-title data-create-title="{{ __('app.add_single_trainer_substitution') }}" data-edit-title="{{ __('app.edit_trainer_substitution') }}">{{ __('app.add_single_trainer_substitution') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.single_trainer_substitution_copy') }}</p>
            </div>
            <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-trainer-substitution-close />
        </div>

        <form method="POST" action="{{ route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]) }}" class="flex min-h-0 flex-1 flex-col" data-trainer-substitution-form="classes" data-store-action="{{ route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]) }}">
            @csrf
            <input type="hidden" name="_method" value="PUT" disabled data-trainer-substitution-method>
            <input type="hidden" name="mode" value="classes">

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    @if ($singleLocation)
                        <input type="hidden" name="location_id" value="{{ $singleLocation->id }}" data-trainer-substitution-location>
                    @else
                        <label class="block">
                            <span class="crm-label">{{ __('app.location') }}</span>
                            <select name="location_id" required class="crm-field" data-trainer-substitution-location>
                                @foreach ($substitutionLocations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                            @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                    @endif

                    <label class="block">
                        <span class="crm-label">{{ __('app.room') }}</span>
                        <select name="room_id" required class="crm-field" data-trainer-substitution-room>
                            @foreach ($substitutionRooms as $room)
                                <option value="{{ $room->id }}" data-location-id="{{ $room->location_id }}">{{ $room->location?->name }} · {{ $room->name }}</option>
                            @endforeach
                        </select>
                        @error('room_id') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <label class="block">
                    <span class="crm-label">{{ __('app.date') }}</span>
                    <input
                        name="class_date"
                        type="date"
                        min="{{ $substitutionPastLimit }}"
                        value="{{ $substitutionToday }}"
                        required
                        class="crm-field"
                        data-trainer-substitution-date
                        data-classes-url="{{ route('dashboard.accounts.trainers.substitutions.classes', [$account, $trainer]) }}"
                        data-loading="{{ __('app.loading') }}"
                        data-empty="{{ __('app.no_trainer_substitution_classes') }}"
                    >
                    @error('class_date') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <div>
                    <div class="crm-label">{{ __('app.classes_to_replace') }}</div>
                    <div class="mt-2 max-h-64 space-y-2 overflow-y-auto rounded-lg border border-stone-200 bg-slate-50 p-2" data-trainer-substitution-class-results data-empty="{{ __('app.no_trainer_substitution_classes') }}">
                        <p class="px-2 py-3 text-sm text-slate-500">{{ __('app.choose_filters_for_trainer_substitution_classes') }}</p>
                    </div>
                    @error('scheduled_class_ids') <span class="crm-help">{{ $message }}</span> @enderror
                    @error('scheduled_class_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
                </div>

                <label class="block">
                    <span class="crm-label">{{ __('app.substitute_trainer') }}</span>
                    <select name="substitute_trainer_id" required class="crm-field" data-trainer-substitution-substitute>
                        <option value="">{{ __('app.choose_trainer') }}</option>
                        @foreach ($substituteTrainers as $substituteTrainer)
                            <option value="{{ $substituteTrainer->id }}">{{ $substituteTrainer->name }}</option>
                        @endforeach
                    </select>
                    @error('substitute_trainer_id') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="flex shrink-0 justify-end gap-2 border-t border-stone-200 bg-white p-5">
                <x-ui.button type="button" variant="secondary" data-trainer-substitution-close>{{ __('app.cancel') }}</x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>

<div
    class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="trainer-substitution-period-title"
    data-trainer-substitution-modal="period"
>
    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
        <div class="flex shrink-0 items-start justify-between gap-4 border-b border-stone-200 p-5">
            <div>
                <h2 id="trainer-substitution-period-title" class="text-lg font-semibold text-slate-950" data-trainer-substitution-title data-create-title="{{ __('app.add_period_trainer_substitution') }}" data-edit-title="{{ __('app.edit_trainer_substitution') }}">{{ __('app.add_period_trainer_substitution') }}</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">{{ __('app.period_trainer_substitution_copy') }}</p>
            </div>
            <x-ui.action-button type="button" icon="close" :label="__('app.cancel')" data-trainer-substitution-close />
        </div>

        <form method="POST" action="{{ route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]) }}" class="flex min-h-0 flex-1 flex-col" data-trainer-substitution-form="period" data-store-action="{{ route('dashboard.accounts.trainers.substitutions.store', [$account, $trainer]) }}">
            @csrf
            <input type="hidden" name="_method" value="PUT" disabled data-trainer-substitution-method>
            <input type="hidden" name="mode" value="period">

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.date_from') }}</span>
                        <input name="date_from" type="date" min="{{ $substitutionToday }}" value="{{ $substitutionToday }}" required class="crm-field" data-trainer-substitution-date-from>
                        @error('date_from') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.date_to') }}</span>
                        <input name="date_to" type="date" min="{{ $substitutionToday }}" value="{{ $substitutionToday }}" required class="crm-field" data-trainer-substitution-date-to>
                        @error('date_to') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @if ($singleLocation)
                        <input type="hidden" name="location_id" value="{{ $singleLocation->id }}" data-trainer-substitution-location>
                    @else
                        <label class="block">
                            <span class="crm-label">{{ __('app.location') }}</span>
                            <select name="location_id" required class="crm-field" data-trainer-substitution-location>
                                @foreach ($substitutionLocations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                            @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                    @endif

                    <label class="block">
                        <span class="crm-label">{{ __('app.room') }}</span>
                        <select name="room_id" required class="crm-field" data-trainer-substitution-room>
                            @foreach ($substitutionRooms as $room)
                                <option value="{{ $room->id }}" data-location-id="{{ $room->location_id }}">{{ $room->location?->name }} · {{ $room->name }}</option>
                            @endforeach
                        </select>
                        @error('room_id') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div>
                    <div class="crm-label">{{ __('app.class_types') }}</div>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($substitutionClassTypes as $classType)
                            <label class="flex items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-2 text-sm font-medium text-slate-700">
                                <input name="class_type_ids[]" type="checkbox" value="{{ $classType->id }}" class="crm-checkbox" data-trainer-substitution-class-type>
                                {{ $classType->name }}
                            </label>
                        @endforeach
                    </div>
                    @error('class_type_ids') <span class="crm-help">{{ $message }}</span> @enderror
                    @error('class_type_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
                </div>

                <label class="block">
                    <span class="crm-label">{{ __('app.substitute_trainer') }}</span>
                    <select name="substitute_trainer_id" required class="crm-field" data-trainer-substitution-substitute>
                        <option value="">{{ __('app.choose_trainer') }}</option>
                        @foreach ($substituteTrainers as $substituteTrainer)
                            <option value="{{ $substituteTrainer->id }}">{{ $substituteTrainer->name }}</option>
                        @endforeach
                    </select>
                    @error('substitute_trainer_id') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>

            <div class="flex shrink-0 justify-end gap-2 border-t border-stone-200 bg-white p-5">
                <x-ui.button type="button" variant="secondary" data-trainer-substitution-close>{{ __('app.cancel') }}</x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
