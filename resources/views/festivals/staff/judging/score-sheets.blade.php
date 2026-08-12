@extends('layouts.app')

@section('title', __('app.festival_score_sheets').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_score_sheets')" :copy="__('app.festival_score_sheets_page_copy')">
        @if ($workspacePermissions['manage'])
            <x-slot:actions>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.score-sheets.prepare', [$account, $edition]) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">
                        <x-ui.icon name="clipboard-check" class="h-4 w-4" />
                        {{ __('app.festival_prepare_score_sheets') }}
                    </x-ui.button>
                </form>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @if ($assignment)
        <p class="text-sm text-slate-600">{{ __('app.festival_score_sheets_for_judge', ['judge' => $assignment->display_name]) }}</p>
    @elseif ($workspacePermissions['manage'])
        <x-ui.panel>
            <p class="text-sm text-slate-600">{{ __('app.festival_manager_score_privacy_copy') }}</p>
        </x-ui.panel>
    @endif

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition])"
        class="sm:grid-cols-3"
    >
        <label>
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="q" value="{{ $filters['q'] }}" class="crm-field" placeholder="{{ __('app.festival_score_sheet_name_placeholder') }}">
        </label>
        <label>
            <span class="crm-label">{{ __('app.status') }}</span>
            <select name="status" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                <option value="draft" @selected($filters['status'] === 'draft')>{{ __('app.festival_score_sheet_status_draft') }}</option>
                <option value="submitted" @selected($filters['status'] === 'submitted')>{{ __('app.festival_score_sheet_status_submitted') }}</option>
            </select>
        </label>
        <label>
            <span class="crm-label">{{ __('app.festival_category') }}</span>
            <select name="category_id" class="crm-field">
                <option value="">{{ __('app.all') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category_id'] === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
    </x-ui.filter-bar>

    <x-ui.panel padding="none" class="overflow-hidden">
        @forelse ($sheets as $sheet)
            @php($ownsSheet = $assignment?->id === $sheet->festival_judge_assignment_id)
            <div class="crm-row lg:grid-cols-[minmax(0,1fr)_180px_170px_minmax(12rem,auto)] lg:items-center">
                <div class="min-w-0">
                    <h2 class="truncate font-semibold text-slate-950">
                        {{ $ownsSheet ? $sheet->entry->entry_name : __('app.festival_private_score_sheet_label', ['id' => $sheet->id]) }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">{{ $sheet->rubric->name }} · {{ $sheet->assignment->display_name }}</p>
                </div>
                <p class="text-sm text-slate-600">{{ $sheet->entry->category->name }}</p>
                <div>
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $sheet->status->value === 'submitted' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ __('app.festival_score_sheet_status_'.$sheet->status->value) }}</span>
                    @if($ownsSheet || ! $workspacePermissions['manage'])
                        <p class="mt-2 text-xs text-slate-500">{{ __('app.festival_score_total', ['score' => $sheet->total_score]) }}</p>
                    @endif
                </div>
                @if($ownsSheet && $sheet->status->value === 'draft')
                    <x-ui.action-button :href="route('dashboard.accounts.festivals.judging.score-sheets.edit', [$account, $edition, $sheet])" icon="edit" :label="__('app.edit')" />
                @elseif($workspacePermissions['manage'] && $sheet->status->value === 'submitted')
                    <form method="POST" action="{{ route('dashboard.accounts.festivals.judging.score-sheets.unlock', [$account, $edition, $sheet]) }}" class="flex flex-col gap-2 sm:flex-row">
                        @csrf
                        @method('PATCH')
                        <label class="min-w-0 flex-1">
                            <span class="sr-only">{{ __('app.festival_unlock_reason') }}</span>
                            <input name="reason" required minlength="3" maxlength="2000" class="crm-field" placeholder="{{ __('app.festival_unlock_reason') }}">
                        </label>
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.festival_unlock_scores') }}</x-ui.button>
                    </form>
                @else
                    <span class="text-sm text-slate-500">{{ __('app.festival_score_sheet_locked') }}</span>
                @endif
            </div>
        @empty
            <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_score_sheets_empty')" icon="clipboard-check" class="m-5">
                @if ($hasFilters)
                    <x-ui.button :href="route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                @else
                    {{ $workspacePermissions['manage'] && ! $assignment ? __('app.festival_manager_score_privacy_copy') : __('app.festival_score_sheets_empty_copy') }}
                @endif
            </x-ui.empty-state>
        @endforelse
    </x-ui.panel>

    <div>{{ $sheets->links() }}</div>
</x-festivals.staff.workspace>
@endsection
