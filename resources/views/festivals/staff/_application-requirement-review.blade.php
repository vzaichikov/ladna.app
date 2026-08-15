@php
    $latestSubmission = $requirement->submissions->first();
    $requirementStatusClasses = match ($requirement->status) {
        \App\Enums\FestivalRequirementStatus::Submitted => 'crm-status-warning',
        \App\Enums\FestivalRequirementStatus::Accepted => 'crm-status-active',
        \App\Enums\FestivalRequirementStatus::Rejected => 'crm-status-danger',
        default => 'crm-status-muted',
    };
@endphp

<div
    class="rounded-lg border border-stone-200 bg-white p-3"
    data-festival-application-fragment
    data-festival-application-fragment-key="requirement-{{ $requirement->id }}"
>
    <div
        data-async-form-status
        data-error-message="{{ __('app.async_request_failed') }}"
        data-validation-message="{{ __('app.async_validation_failed') }}"
        class="hidden"
        role="status"
        aria-live="polite"
    ></div>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <strong class="text-sm">{{ $requirement->definition->name }}</strong>
            <span class="{{ $requirementStatusClasses }}">
                {{ __('app.festival_requirement_status_'.$requirement->status->value) }}
            </span>
        </div>
        @if ($latestSubmission?->path && $latestSubmission->playbackKind() === null)
            <div class="flex flex-wrap items-center gap-3 text-xs font-semibold">
                <a href="{{ route('dashboard.accounts.festivals.submissions.view', [$account, $latestSubmission]) }}" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:text-brand-800">{{ __('app.open') }} · {{ $latestSubmission->original_name }}</a>
                <a href="{{ route('dashboard.accounts.festivals.submissions.download', [$account, $latestSubmission]) }}" class="text-brand-700 hover:text-brand-800">{{ __('app.download') }}</a>
            </div>
        @endif
    </div>
    @if ($latestSubmission?->path && $latestSubmission->playbackKind() !== null)
        <x-festivals.submission-media :$account :submission="$latestSubmission" class="mt-3" />
    @endif
    @if ($latestSubmission && ! $latestSubmission->path)
        <x-festivals.response-value :definition="$requirement->definition" :value="$latestSubmission->value_json['value'] ?? null" class="mt-3 block rounded-lg bg-slate-50 p-3 text-sm text-slate-700" />
    @endif
    <form
        method="POST"
        action="{{ route('dashboard.accounts.festivals.requirements.review', [$account, $edition, $requirement]) }}"
        class="mt-3 grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
        data-async-form
    >
        @csrf
        @method('PATCH')
        <select name="status" class="crm-field mt-0">
            @unless ($latestSubmission)
                <option value="" selected disabled>{{ __('app.festival_requirement_response_missing') }}</option>
            @endunless
            @foreach ($requirement->definition->input_type === \App\Enums\FestivalRequirementInputType::Agreement
                ? ($latestSubmission ? [\App\Enums\FestivalRequirementStatus::Accepted, \App\Enums\FestivalRequirementStatus::Rejected] : [])
                : ($latestSubmission
                    ? [\App\Enums\FestivalRequirementStatus::Accepted, \App\Enums\FestivalRequirementStatus::Rejected, \App\Enums\FestivalRequirementStatus::Waived]
                    : [\App\Enums\FestivalRequirementStatus::Waived]) as $status)
                <option value="{{ $status->value }}" @selected($requirement->status === $status)>{{ __('app.festival_requirement_status_'.$status->value) }}</option>
            @endforeach
        </select>
        <input name="review_notes" value="{{ $requirement->review_notes }}" placeholder="{{ __('app.notes') }}" class="crm-field mt-0">
        <x-ui.button type="submit" size="sm">{{ __('app.save') }}</x-ui.button>
    </form>
</div>
