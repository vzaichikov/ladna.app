@props(['account', 'edition', 'workflow', 'steps', 'hasFilters' => false])

@php
    $paginated = $steps instanceof \Illuminate\Contracts\Pagination\Paginator;
    $visibleSteps = $paginated ? $steps->getCollection() : $steps;
    $ordinaryTotal = $paginated
        ? max(0, $steps->total() - ($hasFilters ? 0 : 1))
        : $visibleSteps->reject(fn ($step) => $step->type === \App\Enums\FestivalWorkflowStepType::Summary)->count();
@endphp

@error('festival_workflow_step')
    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ $message }}</div>
@enderror

<x-ui.panel padding="none" class="overflow-hidden">
    @forelse ($visibleSteps as $step)
        @php
            $isSummary = $step->type === \App\Enums\FestivalWorkflowStepType::Summary;
            $globalIndex = $paginated ? (($steps->firstItem() ?? 1) + $loop->index) : ($loop->index + 1);
            $usageCount = $step->entry_steps_count + $step->requirement_definitions_count + $step->charge_definitions_count;
        @endphp
        <div class="crm-row lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-center">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-semibold text-slate-950">{{ $step->title }}</h3>
                    @if ($isSummary)
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">{{ __('app.festival_summary_system_step') }}</span>
                    @elseif (! $step->is_active)
                        <span class="rounded-full bg-stone-100 px-2.5 py-1 text-xs font-semibold text-stone-600">{{ __('app.inactive') }}</span>
                    @endif
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ __('app.festival_step_type_'.$step->type->value) }} · {{ __('app.festival_review_mode_'.$step->review_mode->value) }}</p>
            </div>
            <p class="text-sm text-slate-500">{{ trans_choice('app.festival_dependency_usage_count', $usageCount, ['count' => $usageCount]) }}</p>
            <x-festivals.settings-actions
                :active="$step->is_active"
                :toggle-route="route('dashboard.accounts.festivals.workflow-steps.toggle', [$account, $edition, $workflow, $step])"
                :move-route="route('dashboard.accounts.festivals.workflow-steps.move', [$account, $edition, $workflow, $step])"
                :edit-route="route('dashboard.accounts.festivals.workflow-steps.edit', [$account, $edition, $workflow, $step])"
                :delete-route="$isSummary ? null : route('dashboard.accounts.festivals.workflow-steps.destroy', [$account, $edition, $workflow, $step])"
                :delete-confirm-title="__('app.festival_delete_workflow_step_title')"
                :delete-confirm-body="__('app.festival_delete_workflow_step_copy')"
                :show-ordering="! $hasFilters && ! $isSummary"
                :show-toggle="! $isSummary"
                :can-move-up="$globalIndex > 1"
                :can-move-down="$globalIndex < $ordinaryTotal"
            />
        </div>
    @empty
        <x-ui.empty-state :title="$hasFilters ? __('app.no_data') : __('app.festival_workflow_steps_empty')" icon="list-tree" class="m-5">{{ $hasFilters ? __('app.festival_filtered_empty_copy') : __('app.festival_workflow_steps_empty_copy') }}</x-ui.empty-state>
    @endforelse
</x-ui.panel>
