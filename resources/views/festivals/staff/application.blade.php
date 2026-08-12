@extends('layouts.app')

@section('title', __('app.festival_application').' - '.$entry->entry_name)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_application')" :copy="$entry->entry_name.' · '.$entry->code">
        <x-slot:actions>
            @if ($canDeleteApplication)
                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.festivals.applications.destroy', [$account, $edition, $entry]) }}"
                    data-confirm-delete
                    data-confirm-title="{{ __('app.festival_delete_application_title') }}"
                    data-confirm-body="{{ __('app.festival_delete_application_copy') }}"
                    data-confirm-accept="{{ __('app.festival_delete_application') }}"
                >
                    @csrf
                    @method('DELETE')
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
            <x-ui.button :href="route('dashboard.accounts.festivals.applications', [$account, $edition])" variant="secondary">{{ __('app.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @error('festival_application')
        <div class="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{{ $message }}</div>
    @enderror

    <section class="rounded-2xl border border-stone-200 bg-slate-50/70 p-5 shadow-crm">
        @include('festivals.staff._application-review')
    </section>
</x-festivals.staff.workspace>
@endsection
