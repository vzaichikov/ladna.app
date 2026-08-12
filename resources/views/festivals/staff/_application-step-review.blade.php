<section
    data-festival-application-fragment
    data-festival-application-fragment-key="step-{{ $entry->id }}"
>
    <div
        data-async-form-status
        data-error-message="{{ __('app.async_request_failed') }}"
        data-validation-message="{{ __('app.async_validation_failed') }}"
        class="hidden"
        role="status"
        aria-live="polite"
    ></div>

    <h3 class="font-semibold text-slate-950">{{ __('app.festival_application_review') }}</h3>
    @if($currentStep)
        <div class="mt-3 rounded-xl border border-stone-200 bg-white p-4">
            <p class="text-xs font-semibold text-brand-700">{{ __('app.festival_current_step') }}</p>
            <strong class="mt-1 block">{{ $currentStep->workflowStep->title }}</strong>
            <span class="mt-1 block text-xs text-slate-500">{{ __('app.festival_step_status_'.$currentStep->status->value) }}</span>
            @if($currentStep->review_notes)
                <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $currentStep->review_notes }}</p>
            @endif
        </div>
        @if($currentStep->status === \App\Enums\FestivalEntryStepStatus::Submitted)
            <form
                method="POST"
                action="{{ route('dashboard.accounts.festivals.entry-steps.review', [$account, $edition, $entry, $currentStep]) }}"
                class="mt-3 grid gap-3 sm:grid-cols-2"
                data-async-form
            >
                @csrf
                @method('PATCH')
                <label>
                    <span class="crm-label">{{ __('app.status') }}</span>
                    <select name="decision" class="crm-field">
                        <option value="approve">{{ __('app.festival_review_approve') }}</option>
                        <option value="request_changes">{{ __('app.festival_review_request_changes') }}</option>
                        <option value="reject_entry">{{ __('app.festival_review_reject_entry') }}</option>
                    </select>
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_correction_due_at') }}</span>
                    <input type="datetime-local" name="correction_due_at" class="crm-field">
                </label>
                <label class="sm:col-span-2">
                    <span class="crm-label">{{ __('app.festival_review_comment') }}</span>
                    <textarea name="comment" rows="3" class="crm-field"></textarea>
                </label>
                <div class="sm:col-span-2">
                    <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
                </div>
            </form>
        @endif
    @else
        <p class="mt-3 rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ __('app.festival_registration_complete') }}</p>
    @endif
</section>
