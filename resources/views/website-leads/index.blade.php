@extends('layouts.app')

@section('title', __('app.website_leads').' - '.$account->name)

@section('content')
    <div>
        <div>
            <h1 class="crm-page-title">{{ __('app.website_leads') }}</h1>
            <p class="crm-page-copy">{{ __('app.website_leads_copy') }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('dashboard.accounts.website-leads.index', $account) }}" class="mt-6 rounded-xl border border-stone-200 bg-white p-4 shadow-xs">
        <div class="grid gap-3 sm:grid-cols-[1fr_220px_auto_auto] sm:items-end">
            <label class="block">
                <span class="crm-label">{{ __('app.search') }}</span>
                <input name="q" value="{{ $searchTerm }}" class="crm-field" placeholder="{{ __('app.website_lead_search_placeholder') }}">
            </label>
            <label class="block">
                <span class="crm-label">{{ __('app.status') }}</span>
                <select name="status" class="crm-field">
                    <option value="">{{ __('app.all_statuses') }}</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($activeStatus === $status->value)>{{ __('app.website_lead_status_'.$status->value) }}</option>
                    @endforeach
                </select>
            </label>
            <x-ui.button type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.website-leads.index', $account)" variant="secondary">{{ __('app.reset_filters') }}</x-ui.button>
        </div>
    </form>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        @forelse ($websiteLeads as $websiteLead)
            <div class="crm-row lg:grid-cols-[1fr_160px_220px_auto] lg:items-center">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-slate-950">{{ $websiteLead->name ?: __('app.no_name') }}</h2>
                        <span class="{{ $websiteLead->status->badgeClass() }}">{{ __('app.website_lead_status_'.$websiteLead->status->value) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">{{ $websiteLead->phone }}</p>
                    @if ($websiteLead->source_page)
                        <p class="mt-1 truncate text-sm text-slate-500">{{ $websiteLead->source_page }}</p>
                    @endif
                </div>
                <div class="text-sm font-medium text-slate-500">{{ $websiteLead->created_at?->copy()->timezone($websiteLeadTimezone)->format('Y-m-d H:i') }}</div>
                <form method="POST" action="{{ route('dashboard.accounts.website-leads.update', [$account, $websiteLead]) }}" class="flex gap-2">
                    @csrf
                    @method('PATCH')
                    <select name="status" class="crm-field mt-0 min-w-36">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($websiteLead->status === $status)>{{ __('app.website_lead_status_'.$status->value) }}</option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.save') }}</x-ui.button>
                </form>
                <div class="flex flex-wrap gap-2 lg:ml-4 lg:justify-end">
                    @foreach ($quickBookingOptions as $quickBookingOption)
                        <x-ui.button
                            type="button"
                            size="sm"
                            data-quick-booking-open="{{ $quickBookingOption['kind']->value }}"
                            data-quick-booking-prefill-name="{{ $websiteLead->name }}"
                            data-quick-booking-prefill-phone="{{ $websiteLead->phone }}"
                            data-quick-booking-prefill-lead="{{ $websiteLead->id }}"
                        >
                            <x-ui.icon name="plus" class="h-4 w-4" />
                            {{ __('app.quick_booking_'.$quickBookingOption['kind']->value.'_button') }}
                        </x-ui.button>
                    @endforeach
                    <form method="POST" action="{{ route('dashboard.accounts.website-leads.destroy', [$account, $websiteLead]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                    </form>
                </div>
            </div>
        @empty
            <x-ui.empty-state :title="__('app.no_website_leads')" icon="website-leads" class="m-5" />
        @endforelse
    </x-ui.panel>

    <div class="mt-6">
        {{ $websiteLeads->links() }}
    </div>

    @include('quick-bookings._modals', [
        'quickBookingOptions' => $quickBookingOptions,
        'quickBookingLocations' => $quickBookingLocations,
        'quickBookingRooms' => $quickBookingRooms,
        'quickBookingTrainers' => $quickBookingTrainers,
        'quickBookingActivityDirections' => $quickBookingActivityDirections,
        'groupAvailabilityUrl' => $groupAvailabilityUrl,
        'manualAvailabilityUrl' => $manualAvailabilityUrl,
        'customerSearchUrl' => $customerSearchUrl,
    ])
@endsection
