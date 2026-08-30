<form
    method="POST"
    action="{{ route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $entry]) }}"
    data-festival-application-fragment
    data-festival-application-fragment-key="full-confirmation-{{ $entry->id }}"
    data-confirm-action
    data-confirm-refresh-url="{{ route('dashboard.accounts.festivals.applications.fully-confirm.preview', [$account, $edition, $entry]) }}"
    data-confirm-refresh-error-title="{{ __('app.festival_full_confirm_refresh_failed_title') }}"
    data-confirm-refresh-error-copy="{{ __('app.festival_full_confirm_refresh_failed_copy') }}"
    data-confirm-title="{{ __($finalConfirmationBlockers !== [] ? 'app.festival_full_confirm_blocked_title' : 'app.festival_full_confirm_title') }}"
    data-confirm-body="{{ __($finalConfirmationBlockers !== [] ? 'app.festival_full_confirm_blocked_copy' : 'app.festival_full_confirm_copy') }}"
    data-confirm-accept="{{ __('app.festival_full_confirm') }}"
    data-confirm-close="{{ __('app.close') }}"
    data-confirm-icon="{{ $finalConfirmationBlockers !== [] ? 'triangle-alert' : 'circle-check' }}"
    data-confirm-variant="{{ $finalConfirmationBlockers !== [] ? 'warning' : 'success' }}"
    @if ($finalConfirmationBlockers !== [])
        data-confirm-blocked="true"
        data-confirm-details='@json($finalConfirmationBlockers)'
    @endif
>
    @csrf
    @method('PATCH')
    <x-ui.button type="submit" variant="success">
        <x-ui.icon name="circle-check" class="h-4 w-4" />{{ __('app.festival_full_confirm') }}
    </x-ui.button>
</form>
