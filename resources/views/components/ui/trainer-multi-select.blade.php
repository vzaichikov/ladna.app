@props([
    'trainers',
    'selectedIds' => [],
    'inputId',
])

@php
    $selectedTrainerIds = collect($selectedIds)
        ->map(fn ($trainerId): int => (int) $trainerId)
        ->unique()
        ->values()
        ->all();
@endphp

<div
    data-trainer-multi-select
    data-no-results="{{ __('app.no_matching_trainers') }}"
    data-remove-label="{{ __('app.remove_additional_trainer') }}"
>
    <label for="{{ $inputId }}" class="crm-label">{{ __('app.additional_trainers') }}</label>
    <p class="mt-1 text-sm text-slate-500">{{ __('app.additional_trainers_help') }}</p>

    <select
        id="{{ $inputId }}"
        name="additional_trainer_ids[]"
        multiple
        class="crm-field min-h-32"
        data-trainer-multi-select-values
        data-async-field="additional_trainer_ids"
    >
        @foreach ($trainers as $trainer)
            <option value="{{ $trainer->id }}" @selected(in_array($trainer->id, $selectedTrainerIds, true))>{{ $trainer->name }}</option>
        @endforeach
    </select>

    <div class="mt-3 hidden" data-trainer-multi-select-ui>
        <div class="mb-2 flex flex-wrap gap-2" data-trainer-multi-select-selected></div>
        <div class="relative">
            <input
                type="search"
                class="crm-field mt-0"
                autocomplete="off"
                placeholder="{{ __('app.search_and_add_trainer') }}"
                aria-autocomplete="list"
                data-trainer-multi-select-input
            >
            <div
                class="absolute z-30 mt-1 hidden max-h-56 w-full overflow-y-auto rounded-lg border border-stone-200 bg-white py-1 shadow-lg"
                role="listbox"
                data-trainer-multi-select-results
            ></div>
        </div>
    </div>

    <div data-async-error-for="additional_trainer_ids"></div>
</div>
