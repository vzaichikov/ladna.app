@extends('layouts.app')

@section('title', __('app.festival_application').' - '.$entry->entry_name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_application')" :copy="$entry->entry_name.' · '.$entry->code">
        <x-slot:actions>
            @if ($workspacePermissions['registrations'] && in_array($entry->status, [\App\Enums\FestivalEntryStatus::Submitted, \App\Enums\FestivalEntryStatus::UnderReview, \App\Enums\FestivalEntryStatus::ChangesPending], true))
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.festivals.applications.fully-confirm', [$account, $edition, $entry]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __($finalConfirmationBlockers !== [] ? 'app.festival_full_confirm_blocked_title' : 'app.festival_full_confirm_title') }}"
                    data-confirm-body="{{ __($finalConfirmationBlockers !== [] ? 'app.festival_full_confirm_blocked_copy' : 'app.festival_full_confirm_copy') }}"
                    data-confirm-accept="{{ __('app.festival_full_confirm') }}"
                    data-confirm-icon="{{ $finalConfirmationBlockers !== [] ? 'triangle-alert' : 'circle-check' }}"
                    data-confirm-variant="{{ $finalConfirmationBlockers !== [] ? 'warning' : 'success' }}"
                    @if ($finalConfirmationBlockers !== [])
                        data-confirm-blocked="true"
                        data-confirm-close="{{ __('app.close') }}"
                        data-confirm-details='@json($finalConfirmationBlockers)'
                    @endif
                >
                    @csrf
                    @method('PATCH')
                    <x-ui.button type="submit" variant="success">
                        <x-ui.icon name="circle-check" class="h-4 w-4" />{{ __('app.festival_full_confirm') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($workspacePermissions['registrations'] && ! in_array($entry->status, [\App\Enums\FestivalEntryStatus::Rejected, \App\Enums\FestivalEntryStatus::Withdrawn], true))
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.festivals.applications.fully-decline', [$account, $edition, $entry]) }}"
                    data-confirm-action
                    data-confirm-title="{{ __('app.festival_full_decline_title') }}"
                    data-confirm-body="{{ __('app.festival_full_decline_copy') }}"
                    data-confirm-accept="{{ __('app.festival_full_decline') }}"
                    data-confirm-icon="circle-x"
                    data-confirm-variant="danger"
                    data-confirm-reason-required="true"
                    data-confirm-reason-label="{{ __('app.festival_full_decline_reason') }}"
                    data-confirm-reason-placeholder="{{ __('app.festival_full_decline_reason_placeholder') }}"
                    data-confirm-reason-help="{{ __('app.festival_full_decline_reason_help') }}"
                >
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="reason" value="{{ old('reason') }}" data-confirm-reason-output>
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="circle-x" class="h-4 w-4" />{{ __('app.festival_full_decline') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($canDeleteApplication)
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $entry]) }}"
                    data-confirm-delete
                    data-confirm-title="{{ __($deleteApplicationRequiresPaymentConfirmation ? 'app.festival_delete_paid_application_title' : 'app.festival_delete_application_title') }}"
                    data-confirm-body="{{ __($deleteApplicationRequiresPaymentConfirmation ? 'app.festival_delete_paid_application_copy' : 'app.festival_delete_application_copy') }}"
                    data-confirm-accept="{{ __('app.festival_delete_application') }}"
                    @if ($deleteApplicationRequiresPaymentConfirmation)
                        data-confirm-phrase="{{ $deleteApplicationConfirmationPhrase }}"
                        data-confirm-phrase-help="{{ __('app.festival_delete_paid_application_confirmation_help', ['phrase' => $deleteApplicationConfirmationPhrase]) }}"
                        data-confirm-phrase-placeholder="{{ $deleteApplicationConfirmationPhrase }}"
                    @endif
                >
                    @csrf
                    @method('DELETE')
                    @if ($deleteApplicationRequiresPaymentConfirmation)
                        <input type="hidden" name="approval" value="" data-confirm-approval-output>
                    @endif
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="trash" class="h-4 w-4" />{{ __('app.festival_delete_application') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($entry->status === \App\Enums\FestivalEntryStatus::Accepted && $workspacePermissions['registrations'])
                <x-ui.button :href="route('dashboard.accounts.festivals.performances.show', [$account, $edition, $entry])" variant="secondary">
                    <x-ui.icon name="eye" class="h-4 w-4" />{{ __('app.festival_readonly_summary') }}
                </x-ui.button>
            @endif
            @if ($workspacePermissions['registrations'])
                <x-ui.button :href="route('dashboard.accounts.festivals.applications.history', [$account, $edition, $entry])" variant="secondary">
                    <x-ui.icon name="history" class="h-4 w-4" />{{ __('app.festival_application_tab_history') }}
                </x-ui.button>
            @endif
            <x-ui.button :href="route('dashboard.accounts.festivals.applications', [$account, $edition])" variant="secondary">{{ __('app.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @error('festival_application')
        <div class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $message }}</div>
    @enderror
    @error('approval')
        <div class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $message }}</div>
    @enderror
    @error('reason')
        <div class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $message }}</div>
    @enderror
    @error('festival_category_id')
        <div class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $message }}</div>
    @enderror

    <section class="rounded-2xl border border-stone-200 bg-slate-50/70 p-5 shadow-crm">
        @include('festivals.staff._application-review')
    </section>
</x-festivals.staff.workspace>
@endsection
