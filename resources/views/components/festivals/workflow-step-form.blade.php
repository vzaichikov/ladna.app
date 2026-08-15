@props(['account', 'edition', 'workflow', 'step' => null, 'hasSummaryStep' => false])

@php
    $editing = $step?->exists;
    $isSummary = $step?->type === \App\Enums\FestivalWorkflowStepType::Summary;
@endphp

<form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.workflow-steps.update', [$account, $edition, $workflow, $step]) : route('dashboard.accounts.festivals.workflow-steps.store', [$account, $edition, $workflow]) }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <label>
        <span class="crm-label">{{ __('app.title') }}</span>
        <input name="title" value="{{ old('title', $step?->title) }}" required class="crm-field">
        <x-ui.field-error name="title" />
        <x-ui.field-error name="code" />
    </label>
    @if ($isSummary)
        <input type="hidden" name="type" value="summary">
        <input type="hidden" name="sort_order" value="{{ $step->sort_order }}">
        <input type="hidden" name="review_mode" value="automatic">
        <input type="hidden" name="review_effect" value="none">
        <input type="hidden" name="opens_at" value="">
        <input type="hidden" name="due_at" value="">
        <input type="hidden" name="is_active" value="1">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 sm:col-span-2 lg:col-span-3">
            <strong>{{ __('app.festival_summary_system_step') }}</strong>
            <p class="mt-1">{{ __('app.festival_summary_step_protected_copy') }}</p>
        </div>
        <x-ui.field-error name="type" class="sm:col-span-2 lg:col-span-4" />
    @else
        <label>
            <span class="crm-label">{{ __('app.festival_step_type') }}</span>
            <select name="type" class="crm-field">
                @foreach (\App\Enums\FestivalWorkflowStepType::cases() as $type)
                    @continue($type === \App\Enums\FestivalWorkflowStepType::Summary && $hasSummaryStep)
                    <option value="{{ $type->value }}" @selected(old('type', $step?->type?->value) === $type->value)>{{ __('app.festival_step_type_'.$type->value) }}</option>
                @endforeach
            </select>
            <x-ui.field-error name="type" />
        </label>
        <label>
            <span class="crm-label">{{ __('app.sort_order') }}</span>
            <input type="number" min="0" max="10000" name="sort_order" value="{{ old('sort_order', $step?->sort_order ?? 10) }}" required class="crm-field">
            <x-ui.field-error name="sort_order" />
        </label>
        <label>
            <span class="crm-label">{{ __('app.festival_review_mode') }}</span>
            <select name="review_mode" class="crm-field">
                @foreach (\App\Enums\FestivalWorkflowReviewMode::cases() as $mode)
                    <option value="{{ $mode->value }}" @selected(old('review_mode', $step?->review_mode?->value ?? 'automatic') === $mode->value)>{{ __('app.festival_review_mode_'.$mode->value) }}</option>
                @endforeach
            </select>
            <x-ui.field-error name="review_mode" />
        </label>
        <label>
            <span class="crm-label">{{ __('app.festival_review_effect') }}</span>
            <select name="review_effect" class="crm-field">
                @foreach (\App\Enums\FestivalWorkflowReviewEffect::cases() as $effect)
                    <option value="{{ $effect->value }}" @selected(old('review_effect', $step?->review_effect?->value ?? 'none') === $effect->value)>{{ __('app.festival_review_effect_'.$effect->value) }}</option>
                @endforeach
            </select>
            <x-ui.field-error name="review_effect" />
        </label>
        <label>
            <span class="crm-label">{{ __('app.opens_at') }}</span>
            <input type="datetime-local" name="opens_at" value="{{ old('opens_at', $step?->opens_at?->format('Y-m-d\TH:i')) }}" class="crm-field">
            <x-ui.field-error name="opens_at" />
        </label>
        <label>
            <span class="crm-label">{{ __('app.due_at') }}</span>
            <input type="datetime-local" name="due_at" value="{{ old('due_at', $step?->due_at?->format('Y-m-d\TH:i')) }}" class="crm-field">
            <x-ui.field-error name="due_at" />
        </label>
    @endif
    <label class="sm:col-span-2 lg:col-span-4">
        <span class="crm-label">{{ __('app.description') }}</span>
        <textarea name="description" rows="2" class="crm-field">{{ old('description', $step?->description) }}</textarea>
        <x-ui.field-error name="description" />
    </label>
    @unless ($isSummary)
        <div>
            <label class="flex items-center gap-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $step?->is_active ?? true))>
                <span class="text-sm">{{ __('app.active') }}</span>
            </label>
            <x-ui.field-error name="is_active" />
        </div>
    @endunless
    <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
        <x-ui.button type="submit"><x-ui.icon name="save" class="h-4 w-4" />{{ __('app.save') }}</x-ui.button>
        <x-ui.button :href="route('dashboard.accounts.festivals.workflows.edit', [$account, $edition, $workflow])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
    </div>
</form>
