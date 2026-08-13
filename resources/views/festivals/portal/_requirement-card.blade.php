@php
    $definition = $requirement->definition;
    $inputType = $definition->input_type;
    $subjectLabel = $requirement->participant?->displayName()
        ?? ($definition->subject_scope === \App\Enums\FestivalFieldScope::Registrant ? $portalUser->displayName() : $entry->entry_name);
    $latest = $requirement->submissions->first();
    $currentValue = $latest?->value_json['value'] ?? null;
    $isRejected = $requirement->status === \App\Enums\FestivalRequirementStatus::Rejected;
    $statusClass = match ($requirement->status) {
        \App\Enums\FestivalRequirementStatus::Missing,
        \App\Enums\FestivalRequirementStatus::Rejected => 'crm-status-danger',
        \App\Enums\FestivalRequirementStatus::Submitted => 'crm-status-warning',
        \App\Enums\FestivalRequirementStatus::Accepted => 'crm-status-active',
        \App\Enums\FestivalRequirementStatus::Waived => 'crm-status-muted',
    };
@endphp

<article
    data-festival-requirement-card
    data-festival-requirement-id="{{ $requirement->id }}"
    class="rounded-xl border p-4 {{ $isRejected ? 'border-rose-300 bg-rose-50' : 'border-stone-200 bg-white' }}"
>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <strong>{{ $definition->name }}</strong>
            <span class="ml-2 text-xs text-slate-500">{{ $subjectLabel }}</span>
            @if ($definition->instructions)
                <p class="mt-1 text-sm text-slate-600">{{ $definition->instructions }}</p>
            @endif
        </div>
        <span class="{{ $statusClass }} self-start">{{ __('app.festival_requirement_status_'.$requirement->status->value) }}</span>
    </div>

    @if ($isRejected && filled($requirement->review_notes))
        <p class="mt-3 whitespace-pre-line text-sm font-semibold text-rose-700">{{ $requirement->review_notes }}</p>
    @endif

    @if ($selectedState['mutable'])
        @if ($inputType === \App\Enums\FestivalRequirementInputType::File)
            <form method="POST" enctype="multipart/form-data" action="{{ route('festival.portal.submissions.store', [$account->slug, $entry, $requirement]) }}" data-async-form class="mt-4">
                @csrf
                <div data-async-form-status data-error-message="{{ __('app.async_request_failed') }}" class="hidden"></div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input type="file" name="file" @required($definition->is_required) class="crm-field">
                    <x-ui.button type="submit">{{ __('app.upload') }}</x-ui.button>
                </div>
                <div data-async-error-for="file">
                    @error('file')
                        <span class="crm-help mt-2 block">{{ $message }}</span>
                    @enderror
                </div>
            </form>
        @else
            <form
                method="POST"
                action="{{ route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $selectedStep, $requirement]) }}"
                data-async-form
                class="mt-4"
            >
                @csrf
                <div data-async-form-status data-error-message="{{ __('app.async_request_failed') }}" class="hidden"></div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    @if ($inputType === \App\Enums\FestivalRequirementInputType::Boolean)
                        <fieldset class="grow">
                            <legend class="sr-only">{{ $definition->name }}</legend>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ([1 => __('app.yes'), 0 => __('app.no')] as $value => $label)
                                    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-stone-200 px-4 py-3 transition has-checked:border-brand-500 has-checked:bg-brand-50">
                                        <input
                                            type="radio"
                                            name="value"
                                            value="{{ $value }}"
                                            @checked(($value === 1 && in_array($currentValue, [true, 1, '1'], true)) || ($value === 0 && in_array($currentValue, [false, 0, '0'], true)))
                                            @required($definition->is_required)
                                            data-async-submit-on-change
                                            class="crm-radio"
                                        >
                                        <span class="font-semibold text-slate-800">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @elseif ($inputType === \App\Enums\FestivalRequirementInputType::Agreement)
                        <input type="hidden" name="value" value="0">
                        <label class="flex min-h-12 grow cursor-pointer items-center gap-3 rounded-xl border border-stone-200 px-4 py-3 transition has-checked:border-brand-500 has-checked:bg-brand-50">
                            <input
                                type="checkbox"
                                name="value"
                                value="1"
                                @checked($currentValue === true || $currentValue === 1 || $currentValue === '1')
                                @required($definition->is_required)
                                data-async-submit-on-change
                                class="crm-checkbox"
                            >
                            <span class="font-semibold text-slate-800">{{ __('app.festival_agreement_confirm') }}</span>
                        </label>
                    @else
                        <label class="grow">
                            <span class="sr-only">{{ $definition->name }}</span>
                            @if ($inputType === \App\Enums\FestivalRequirementInputType::LongText)
                                <textarea name="value" rows="4" @required($definition->is_required) class="crm-field">{{ is_scalar($currentValue) ? $currentValue : '' }}</textarea>
                            @elseif (in_array($inputType, [\App\Enums\FestivalRequirementInputType::SingleSelect, \App\Enums\FestivalRequirementInputType::MultiSelect], true))
                                <select name="value{{ $inputType === \App\Enums\FestivalRequirementInputType::MultiSelect ? '[]' : '' }}" @if ($inputType === \App\Enums\FestivalRequirementInputType::MultiSelect) multiple @endif @required($definition->is_required) data-async-submit-on-change class="crm-field">
                                    @foreach (($definition->options ?? []) as $option)
                                        <option value="{{ $option['value'] }}" @selected(collect(is_array($currentValue) ? $currentValue : [$currentValue])->contains($option['value']))>{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="{{ $inputType === \App\Enums\FestivalRequirementInputType::Integer ? 'number' : ($inputType === \App\Enums\FestivalRequirementInputType::Url ? 'url' : 'text') }}" name="value" value="{{ is_scalar($currentValue) ? $currentValue : '' }}" @required($definition->is_required) class="crm-field">
                            @endif
                        </label>
                    @endif
                    <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
                </div>
                <div data-async-error-for="value">
                    @error('value')
                        <span class="crm-help mt-2 block">{{ $message }}</span>
                    @enderror
                </div>
            </form>
        @endif
    @elseif ($inputType !== \App\Enums\FestivalRequirementInputType::File && $latest)
        <x-festivals.response-value :definition="$definition" :value="$currentValue" class="mt-3 block rounded-lg bg-white/70 p-3 text-sm" />
    @endif

    @if ($latest?->path)
        <a href="{{ route('festival.portal.submissions.download', [$account->slug, $latest]) }}" class="mt-3 block break-all text-sm font-semibold text-brand-700">{{ __('app.download') }} · {{ $latest->original_name }}</a>
    @endif
</article>
