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
    @if($entry->status === \App\Enums\FestivalEntryStatus::Rejected)
        <div class="mt-3 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">
            <strong>{{ __('app.festival_summary_rejected_title') }}</strong>
            @if($entry->review_notes)
                <p class="mt-1 whitespace-pre-line">{{ $entry->review_notes }}</p>
            @endif
        </div>
    @elseif($currentStep)
        <div class="mt-3 rounded-xl border border-stone-200 bg-white p-4">
            <p class="text-xs font-semibold text-brand-700">{{ __('app.festival_current_step') }}</p>
            <strong class="mt-1 block">{{ $currentStep->workflowStep->title }}</strong>
            <span class="mt-1 block text-xs text-slate-500">{{ __('app.festival_step_status_'.$currentStep->status->value) }}</span>
            @if($currentStep->review_notes)
                <p class="mt-2 whitespace-pre-line text-sm text-slate-600">{{ $currentStep->review_notes }}</p>
            @endif
        </div>
        @if($currentStep->status === \App\Enums\FestivalEntryStepStatus::Submitted)
            @php
                $stepDecisionConfig = [
                    'approve' => [
                        'button_label' => __('app.festival_review_approve'),
                        'button_variant' => 'success',
                        'confirm_title' => __('app.festival_review_approve_confirm_title'),
                        'confirm_body' => __('app.festival_review_approve_confirm_copy'),
                        'confirm_accept' => __('app.festival_review_approve'),
                        'confirm_icon' => 'circle-check',
                        'confirm_variant' => 'success',
                        'comment_required' => false,
                        'deadline_required' => false,
                    ],
                    'request_changes' => [
                        'button_label' => __('app.festival_review_return_for_correction'),
                        'button_variant' => 'warning',
                        'confirm_title' => __('app.festival_review_return_confirm_title'),
                        'confirm_body' => __('app.festival_review_return_confirm_copy'),
                        'confirm_accept' => __('app.festival_review_return_for_correction'),
                        'confirm_icon' => 'undo-2',
                        'confirm_variant' => 'warning',
                        'comment_required' => true,
                        'deadline_required' => true,
                    ],
                    'reject_entry' => [
                        'button_label' => __('app.festival_review_reject_entry'),
                        'button_variant' => 'danger',
                        'confirm_title' => __('app.festival_review_reject_confirm_title'),
                        'confirm_body' => __('app.festival_review_reject_confirm_copy'),
                        'confirm_accept' => __('app.festival_review_reject_entry'),
                        'confirm_icon' => 'circle-x',
                        'confirm_variant' => 'danger',
                        'comment_required' => true,
                        'deadline_required' => false,
                    ],
                ];
                $stepConfirmationDetails = [[
                    'label' => __('app.festival_activity_step_label'),
                    'value' => $currentStep->workflowStep->title,
                ]];
            @endphp
            <form
                method="POST"
                action="{{ route('dashboard.accounts.festivals.entry-steps.review', [$account, $edition, $entry, $currentStep]) }}"
                class="mt-3 grid gap-3 sm:grid-cols-2"
                data-async-form
                data-confirm-action
                data-festival-decision-form
                data-decision-config='@json($stepDecisionConfig)'
                data-decision-base-details='@json($stepConfirmationDetails)'
                data-decision-comment-label="{{ __('app.festival_review_comment') }}"
                data-decision-deadline-label="{{ __('app.festival_correction_due_at') }}"
                data-decision-empty-value="—"
                data-confirm-title="{{ __('app.festival_review_approve_confirm_title') }}"
                data-confirm-body="{{ __('app.festival_review_approve_confirm_copy') }}"
                data-confirm-accept="{{ __('app.festival_review_approve') }}"
                data-confirm-icon="circle-check"
                data-confirm-variant="success"
                data-confirm-details='@json($stepConfirmationDetails)'
            >
                @csrf
                @method('PATCH')
                <label>
                    <span class="crm-label">{{ __('app.status') }}</span>
                    <select name="decision" class="crm-field" data-festival-decision>
                        <option value="approve">{{ __('app.festival_review_approve') }}</option>
                        <option value="request_changes">{{ __('app.festival_review_return_for_correction') }}</option>
                        <option value="reject_entry">{{ __('app.festival_review_reject_entry') }}</option>
                    </select>
                </label>
                <label>
                    <span class="crm-label">{{ __('app.festival_correction_due_at') }}</span>
                    <input type="datetime-local" name="correction_due_at" class="crm-field" data-festival-decision-deadline>
                </label>
                <label class="sm:col-span-2">
                    <span class="crm-label">{{ __('app.festival_review_comment') }}</span>
                    <textarea name="comment" rows="3" class="crm-field" data-festival-decision-comment></textarea>
                </label>
                <div class="sm:col-span-2">
                    <x-ui.button type="submit" variant="success" data-festival-decision-submit>
                        <span data-festival-decision-submit-label>{{ __('app.festival_review_approve') }}</span>
                    </x-ui.button>
                </div>
            </form>
        @endif
    @else
        @if($entry->status === \App\Enums\FestivalEntryStatus::Accepted)
            <p class="mt-3 rounded-xl bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{{ __('app.festival_registration_complete') }}</p>
        @elseif($entry->status === \App\Enums\FestivalEntryStatus::ChangesPending)
            <p class="mt-3 rounded-xl bg-amber-50 p-4 text-sm font-semibold text-amber-800">{{ __('app.festival_summary_changes_pending_title') }}</p>
        @else
            <p class="mt-3 rounded-xl bg-sky-50 p-4 text-sm font-semibold text-sky-800">{{ __('app.festival_summary_awaiting_title') }}</p>
        @endif
    @endif
</section>
