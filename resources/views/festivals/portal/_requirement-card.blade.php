@php
    $definition = $requirement->definition;
    $requirementStep = $requirementStep ?? $selectedStep;
    $requirementState = $requirementState ?? $selectedState;
    $inputType = $definition->input_type;
    $subjectLabel = $requirement->participant?->displayName()
        ?? ($definition->subject_scope === \App\Enums\FestivalFieldScope::Registrant ? $portalUser->displayName() : $entry->entry_name);
    $latest = $requirement->submissions->first();
    $currentValue = $latest?->value_json['value'] ?? null;
    $teamHelpers = collect($teamHelpers ?? []);
    $helperSelectionEnabled = $inputType === \App\Enums\FestivalRequirementInputType::HelperSelection
        && is_array($currentValue)
        && ($currentValue['enabled'] ?? false) === true;
    $selectedHelperIds = $requirement->relationLoaded('selectedHelpers')
        ? $requirement->selectedHelpers->modelKeys()
        : [];
    $isRejected = $requirement->status === \App\Enums\FestivalRequirementStatus::Rejected;
    $requirementMutable = $requirementState['requirement_mutability'][$requirement->id] ?? $requirementState['mutable'];
    $requirementBlocking = $definition->is_required || $inputType === \App\Enums\FestivalRequirementInputType::Agreement;
    $requirementComplete = $requirementState['requirement_completeness'][$requirement->id] ?? false;
    $editableUntil = $requirementState['editable_until'][$requirement->id] ?? null;
    $dueAt = $requirementState['due_at'][$requirement->id] ?? null;
    $durationLabel = app(\App\Support\Festivals\FestivalMediaDuration::class)->label($definition, $entry->category);
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
    data-festival-requirement-blocking="{{ $requirementBlocking ? 'true' : 'false' }}"
    data-festival-requirement-complete="{{ $requirementComplete ? 'true' : 'false' }}"
    class="rounded-xl border p-4 {{ $isRejected ? 'border-rose-300 bg-rose-50' : 'border-stone-200 bg-white' }}"
>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <strong>{{ $definition->name }}</strong>
            <span class="ml-2 text-xs text-slate-500">{{ $subjectLabel }}</span>
            @if ($definition->instructions)
                <p class="mt-1 text-sm text-slate-600">{{ $definition->instructions }}</p>
            @endif
            @if ($durationLabel)
                <span class="mt-2 inline-flex rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-800">{{ $durationLabel }}</span>
            @endif
        </div>
        <span class="{{ $statusClass }} self-start">{{ __('app.festival_requirement_status_'.$requirement->status->value) }}</span>
    </div>

    @if ($isRejected && filled($requirement->review_notes))
        <p class="mt-3 whitespace-pre-line text-sm font-semibold text-rose-700">{{ $requirement->review_notes }}</p>
    @endif

    @if ($editableUntil)
        <p class="mt-3 rounded-lg bg-brand-50 px-3 py-2 text-sm font-semibold text-brand-800">
            {{ __('app.festival_field_editable_until', ['date' => $editableUntil->timezone($entry->edition->timezone)->format('d.m.Y H:i')]) }}
        </p>
    @endif
    @if ($dueAt && !$requirement->hasSubmittedResponse())
        <p class="mt-3 text-sm font-semibold {{ $dueAt->isPast() ? 'text-rose-700' : 'text-slate-600' }}">
            {{ __('app.festival_field_due_at', ['date' => $dueAt->timezone($entry->edition->timezone)->format('d.m.Y H:i')]) }}
        </p>
    @endif

    @if ($requirementMutable)
        @if ($inputType === \App\Enums\FestivalRequirementInputType::HelperSelection)
            @php($helperListId = 'festival-helper-list-'.$requirement->id)
            <form
                method="POST"
                action="{{ route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $requirementStep, $requirement]) }}"
                data-async-form
                data-festival-helper-selection-form
                class="mt-4"
            >
                @csrf
                <div data-async-form-status data-error-message="{{ __('app.async_request_failed') }}" data-validation-message="{{ __('app.async_validation_failed') }}" class="hidden"></div>
                <input type="hidden" name="value[enabled]" value="0">
                <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-stone-200 px-4 py-3 transition has-checked:border-brand-500 has-checked:bg-brand-50">
                    <input
                        type="checkbox"
                        name="value[enabled]"
                        value="1"
                        class="crm-checkbox"
                        aria-controls="{{ $helperListId }}"
                        aria-expanded="{{ $helperSelectionEnabled ? 'true' : 'false' }}"
                        data-festival-helper-enabled
                        @checked($helperSelectionEnabled)
                    >
                    <span class="font-semibold text-slate-800">{{ $definition->name }}</span>
                </label>
                <div id="{{ $helperListId }}" @class(['mt-3', 'hidden' => ! $helperSelectionEnabled]) data-festival-helper-list>
                    <div class="grid gap-3 sm:grid-cols-2" data-festival-helper-options>
                        @foreach($teamHelpers as $helper)
                            <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-stone-200 p-3 transition has-checked:border-brand-500 has-checked:bg-brand-50" data-festival-helper-option data-festival-helper-id="{{ $helper->id }}">
                                @if($helper->photo_path)
                                    <img src="{{ route('festival.portal.participants.photo', [$account->slug, $helper]) }}" alt="" class="h-11 w-11 shrink-0 rounded-full object-cover">
                                @else
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-800" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $helper->first_name, 0, 1).mb_substr((string) $helper->last_name, 0, 1)) }}</span>
                                @endif
                                <input type="checkbox" name="value[helper_ids][]" value="{{ $helper->id }}" class="crm-checkbox" data-festival-helper-choice @checked(in_array($helper->id, $selectedHelperIds, true))>
                                <span class="min-w-0"><strong class="block truncate text-sm text-slate-900">{{ $helper->displayName() }}</strong><span class="text-xs text-slate-500">{{ $helper->date_of_birth->format('d.m.Y') }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @if($teamHelpers->isEmpty())
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" data-festival-helper-empty>
                            <p>{{ __('app.festival_helpers_empty') }}</p>
                            <a href="{{ route('festival.portal.participants.index', ['accountSlug' => $account->slug, 'add' => 'helper']) }}" class="mt-3 inline-flex min-h-11 items-center font-semibold text-brand-700" data-festival-helper-add>{{ __('app.festival_add_helper') }}</a>
                        </div>
                    @endif
                </div>
                <div data-async-error-for="value" class="mt-2"></div>
                <div data-async-error-for="value.enabled"></div>
                <div data-async-error-for="value.helper_ids"></div>
                <div class="mt-3 flex justify-end"><x-ui.button type="submit">{{ __('app.save') }}</x-ui.button></div>
            </form>
        @elseif ($inputType === \App\Enums\FestivalRequirementInputType::File)
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
                action="{{ route('festival.portal.entry-step-responses.store', [$account->slug, $entry, $requirementStep, $requirement]) }}"
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
        <x-festivals.response-value :definition="$definition" :value="$currentValue" :helpers="$requirement->selectedHelpers" class="mt-3 block rounded-lg bg-white/70 p-3 text-sm" />
    @endif

    @if($requirementMutable && $inputType === \App\Enums\FestivalRequirementInputType::HelperSelection)
        @include('festivals.portal.team._member-modal', [
            'account' => $account,
            'modalId' => 'festival-helper-add-modal-'.$requirement->id,
            'mode' => 'add',
            'defaultMemberType' => \App\Enums\FestivalTeamMemberType::Helper,
            'fragmentContext' => 'helper_selection',
            'open' => false,
            'showErrors' => false,
        ])
    @endif

    @if ($latest?->path)
        <a href="{{ route('festival.portal.submissions.download', [$account->slug, $latest]) }}" class="mt-3 block break-all text-sm font-semibold text-brand-700">{{ __('app.download') }} · {{ $latest->original_name }}</a>
    @endif
</article>
