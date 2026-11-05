@extends('layouts.app')
@section('title', __('app.festival_ticket_types_tab').' - '.$edition->title)
@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_admission_types')" :copy="__('app.festival_admission_types_table_copy')">
        <x-slot:actions><x-ui.button :href="route('dashboard.accounts.festivals.admission-types.create', [$account, $edition])"><x-ui.icon name="plus" class="h-4 w-4" />{{ __('app.festival_add_admission_type') }}</x-ui.button></x-slot:actions>
    </x-ui.page-header>

            <x-ui.filter-bar :action="route('dashboard.accounts.festivals.admission-types.index', [$account, $edition])" :reset-href="route('dashboard.accounts.festivals.admission-types.index', [$account, $edition])" class="sm:grid-cols-2">
                <label><span class="crm-label">{{ __('app.search') }}</span><input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_admission_search_placeholder') }}"></label>
                <label><span class="crm-label">{{ __('app.status') }}</span><select name="status" class="crm-field"><option value="">{{ __('app.all') }}</option><option value="active" @selected($filters['status'] === 'active')>{{ __('app.active') }}</option><option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('app.inactive') }}</option></select></label>
            </x-ui.filter-bar>

            @error('admission_type') <div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $message }}</div> @enderror

            <x-ui.panel padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-[980px] w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">{{ __('app.festival_ticket_type') }}</th><th class="px-4 py-3">{{ __('app.status') }}</th><th class="px-4 py-3">{{ __('app.festival_inventory') }}</th><th class="px-4 py-3">{{ __('app.festival_sold_held') }}</th><th class="px-4 py-3">{{ __('app.festival_remaining') }}</th><th class="px-4 py-3">{{ __('app.price') }}</th><th class="px-4 py-3">{{ __('app.festival_sales_window') }}</th><th class="px-4 py-3">{{ __('app.festival_lock_state') }}</th><th class="px-4 py-3 text-right">{{ __('app.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @forelse ($admissionTypes as $admissionType)
                                @php
                                    $availability = $admissionAvailability[$admissionType->id];
                                @endphp
                                <tr class="align-top">
                                    <td class="px-4 py-4"><strong class="text-slate-950">{{ $admissionType->name }}</strong>@if($admissionType->is_assigned_seating)<span class="ml-2 rounded-full bg-sky-50 px-2 py-1 text-xs font-medium text-sky-900">{{ __('app.festival_seating_admin.assigned') }}</span>@endif @if($admissionType->description)<p class="mt-1 max-w-xs text-xs text-slate-500">{{ $admissionType->description }}</p>@endif</td>
                                    <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $admissionType->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-100 text-stone-700' }}">{{ $admissionType->is_active ? __('app.active') : __('app.inactive') }}</span></td>
                                    <td class="px-4 py-4 font-semibold">{{ $admissionType->inventory }}</td>
                                    <td class="px-4 py-4"><span class="font-semibold">{{ $availability['sold'] }}</span><span class="text-slate-400"> / {{ $availability['held'] }}</span></td>
                                    <td class="px-4 py-4 font-semibold">{{ $availability['remaining'] }}</td>
                                    <td class="px-4 py-4"><strong>{{ \App\Support\MoneyFormatter::format($availability['current_price_cents'], $account->default_currency) }}</strong><span class="mt-1 block text-xs text-slate-500">{{ __('app.festival_price_tier_'.$availability['price_tier']) }}</span></td>
                                    <td class="px-4 py-4 text-xs text-slate-600"><span class="block">{{ $admissionType->sales_starts_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</span><span class="block">{{ $admissionType->sales_ends_at?->timezone($edition->timezone)->format('d.m.Y H:i') ?? '—' }}</span></td>
                                    <td class="px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $availability['locked'] ? 'bg-amber-100 text-amber-800' : 'bg-sky-100 text-sky-800' }}">{{ $availability['locked'] ? __('app.festival_locked_after_purchase') : __('app.festival_editable') }}</span></td>
                                    <td class="px-4 py-4"><div class="flex justify-end gap-2">
                                        @unless ($availability['locked'])
                                            <x-ui.action-button :href="route('dashboard.accounts.festivals.admission-types.edit', [$account, $edition, $admissionType])" icon="edit" :label="__('app.edit')" />
                                        @endunless
                                        @unless ($availability['has_history'])
                                            <form method="POST" action="{{ route('dashboard.accounts.festivals.admission-types.destroy', [$account, $edition, $admissionType]) }}" data-confirm-delete data-confirm-title="{{ __('app.festival_delete_admission_type_title') }}" data-confirm-body="{{ __('app.festival_delete_admission_type_copy') }}">
                                                @csrf @method('DELETE')
                                                <x-ui.action-button type="submit" variant="danger" icon="trash" :label="__('app.delete')" />
                                            </form>
                                        @endunless
                                    </div></td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">{{ $filters['q'] || $filters['status'] ? __('app.no_data') : __('app.festival_admission_types_empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.panel>
            <div>{{ $admissionTypes->links() }}</div>
</x-festivals.staff.workspace>
@endsection
