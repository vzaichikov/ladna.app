@extends('layouts.app')

@section('title', __('app.festival_issue_tickets').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_issue_tickets')" :copy="__('app.festival_issue_tickets_copy')">
        <x-slot:actions>
            <x-ui.button :href="route('dashboard.accounts.festivals.users.create', [$account, $edition, 'guest', 'return_to' => 'ticket-issuance'])" variant="secondary">
                <x-ui.icon name="plus" class="h-4 w-4" />
                {{ __('app.festival_add_guest') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">{{ session('status') }}</div>
    @endif

    <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
        <div><h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_manual_ticket_capacity') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_manual_ticket_capacity_copy') }}</p></div>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @forelse ($admissionTypes as $admissionType)
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="font-semibold text-slate-950">{{ $admissionType->name }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ trans_choice('app.festival_ticket_capacity_remaining', $capacityByType[$admissionType->id], ['count' => $capacityByType[$admissionType->id]]) }}</p>
                </div>
            @empty
                <p class="text-sm text-slate-600">{{ __('app.festival_manual_ticket_no_types') }}</p>
            @endforelse
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <div class="space-y-4">
            <div><h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_select_guest') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_select_guest_copy') }}</p></div>
            <x-ui.filter-bar :action="route('dashboard.accounts.festivals.tickets.issue', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.tickets.issue', [$account, $edition])" class="sm:grid-cols-1">
                @if ($selectedGuest)<input type="hidden" name="selected_guest_id" value="{{ $selectedGuest->id }}">@endif
                <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_guest_search_placeholder') }}"></label>
            </x-ui.filter-bar>
            <x-ui.panel padding="none" class="overflow-hidden">
                @forelse ($guests as $guest)
                    <a href="{{ route('dashboard.accounts.festivals.tickets.issue', [$account, $edition, 'selected_guest_id' => $guest->id, 'q' => $filters['q']]) }}" class="crm-row block transition hover:bg-slate-50 {{ $selectedGuest?->is($guest) ? 'bg-amber-50' : '' }}">
                        <p class="font-semibold text-slate-950">{{ $guest->displayName() }}</p>
                        <p class="mt-1 break-words text-sm text-slate-500">{{ $guest->email }}@if($guest->phone) · {{ $guest->phone }}@endif</p>
                    </a>
                @empty
                    <x-ui.empty-state :title="__('app.festival_guests_empty')" icon="users" class="m-5" />
                @endforelse
            </x-ui.panel>
            <div>{{ $guests->links() }}</div>
        </div>

        <form method="POST" action="{{ route('dashboard.accounts.festivals.tickets.issue.store', [$account, $edition]) }}" class="h-fit rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
            @csrf
            <h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_handmade_ticket') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_handmade_ticket_copy') }}</p>

            @if ($selectedGuest)
                <input type="hidden" name="festival_portal_user_id" value="{{ $selectedGuest->id }}">
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-xs font-semibold uppercase text-amber-800">{{ __('app.festival_selected_guest') }}</p>
                    <p class="mt-1 font-semibold text-slate-950">{{ $selectedGuest->displayName() }}</p>
                    <p class="mt-1 break-words text-sm text-slate-600">{{ $selectedGuest->email }}</p>
                </div>
            @else
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ __('app.festival_select_guest_required') }}</div>
            @endif
            @error('festival_portal_user_id')<span class="crm-help">{{ $message }}</span>@enderror

            <div class="mt-5 space-y-5">
                <label><span class="crm-label">{{ __('app.festival_ticket_type') }}</span><select name="festival_admission_type_id" required class="crm-field"><option value="">{{ __('app.select') }}</option>@foreach($admissionTypes as $admissionType)<option value="{{ $admissionType->id }}" @selected(old('festival_admission_type_id') == $admissionType->id)>{{ $admissionType->name }} · {{ $capacityByType[$admissionType->id] }}</option>@endforeach</select>@error('festival_admission_type_id')<span class="crm-help">{{ $message }}</span>@enderror</label>
                <label><span class="crm-label">{{ __('app.festival_ticket_holder_name') }}</span><input name="holder_name" value="{{ old('holder_name', $selectedGuest?->displayName()) }}" required maxlength="255" class="crm-field">@error('holder_name')<span class="crm-help">{{ $message }}</span>@enderror</label>
            </div>

            <div class="mt-6 flex justify-end"><x-ui.button type="submit" :disabled="! $selectedGuest || $admissionTypes->isEmpty()"><x-ui.icon name="ticket" class="h-4 w-4" />{{ __('app.festival_issue_ticket') }}</x-ui.button></div>
        </form>
    </section>

    <section class="space-y-4">
        <div><h2 class="text-xl font-semibold text-slate-950">{{ __('app.festival_issue_missing') }}</h2><p class="mt-1 text-sm text-slate-600">{{ __('app.festival_issue_missing_copy') }}</p></div>
        <div class="grid gap-5">
            @foreach (['judges' => $judgeStats] as $audience => $stats)
                <form method="POST" action="{{ route('dashboard.accounts.festivals.tickets.issue.audience', [$account, $edition]) }}" class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                    @csrf
                    <input type="hidden" name="audience" value="{{ $audience }}">
                    <h3 class="text-lg font-semibold text-slate-950">{{ __('app.festival_judges') }}</h3>
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach (['eligible', 'already_issued', 'skipped', 'remaining'] as $stat)
                            <div class="rounded-xl bg-slate-50 p-3"><p class="text-xs text-slate-500">{{ __('app.festival_ticket_stat_'.$stat) }}</p><p class="mt-1 text-xl font-semibold text-slate-950">{{ $stats[$stat] }}</p></div>
                        @endforeach
                    </div>
                    <label class="mt-5 block"><span class="crm-label">{{ __('app.festival_ticket_type') }}</span><select name="festival_admission_type_id" required class="crm-field"><option value="">{{ __('app.select') }}</option>@foreach($admissionTypes as $admissionType)<option value="{{ $admissionType->id }}">{{ $admissionType->name }} · {{ $capacityByType[$admissionType->id] }}</option>@endforeach</select></label>
                    <div class="mt-5 flex justify-end"><x-ui.button type="submit" :disabled="$stats['remaining'] === 0 || $admissionTypes->isEmpty()">{{ __('app.festival_issue_missing_action') }}</x-ui.button></div>
                </form>
            @endforeach
        </div>
    </section>
</x-festivals.staff.workspace>
@endsection
