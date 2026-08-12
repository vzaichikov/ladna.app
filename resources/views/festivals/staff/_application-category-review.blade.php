@php
    $category = $entry->category;
    $reassignmentCategories = $canManageRegistrations
        ? $categories->where('is_active', true)->where('festival_workflow_id', $category->festival_workflow_id)->where('id', '!=', $category->id)
        : collect();
@endphp

<section
    class="rounded-xl border border-stone-200 bg-white p-4 xl:col-span-2"
    data-festival-application-fragment
    data-festival-application-fragment-key="category-{{ $entry->id }}"
>
    <div
        data-async-form-status
        data-error-message="{{ __('app.async_request_failed') }}"
        data-validation-message="{{ __('app.async_validation_failed') }}"
        class="hidden"
        role="status"
        aria-live="polite"
    ></div>

    <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ $category->direction->name }}</p>
    <h3 class="mt-1 font-semibold text-slate-950">{{ $category->name }}</h3>
    <dl class="mt-3 flex flex-wrap gap-2 text-xs text-slate-700">
        <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_roster') }}</dt><dd>{{ __('app.festival_participants_range', ['min' => $category->min_members, 'max' => $category->max_members]) }}</dd></div>
        @if($category->min_age !== null || $category->max_age !== null)
            <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_age_limits') }}</dt><dd>{{ __('app.festival_age_range', ['min' => $category->min_age ?? '—', 'max' => $category->max_age ?? '—']) }}</dd></div>
        @endif
        @if($category->min_duration_seconds !== null || $category->max_duration_seconds !== null)
            <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_performance_duration') }}</dt><dd>{{ __('app.festival_duration_range', ['min' => $category->min_duration_seconds ?? '—', 'max' => $category->max_duration_seconds ?? '—']) }}</dd></div>
        @endif
        @if($category->registration_closes_at)
            <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_registration_closes_at') }}</dt><dd>{{ __('app.festival_category_deadline_value', ['date' => $category->registration_closes_at->timezone($edition->timezone)->format('d.m.Y H:i'), 'timezone' => $edition->timezone]) }}</dd></div>
        @endif
    </dl>
    <div class="mt-4 border-t border-stone-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-950">{{ __('app.festival_category_requirements') }}</h4>
        @if($category->requirements_html)
            <div class="prose prose-slate mt-2 max-w-none text-sm">{!! $category->requirements_html !!}</div>
        @else
            <p class="mt-2 text-sm text-slate-500">{{ __('app.festival_category_requirements_none') }}</p>
        @endif
    </div>
    @if($reassignmentCategories->isNotEmpty())
        <form
            method="POST"
            action="{{ route('dashboard.accounts.festivals.entries.reassign-category', [$account, $edition, $entry]) }}"
            class="mt-4 grid gap-3 border-t border-stone-200 pt-4 sm:grid-cols-2"
            data-async-form
        >
            @csrf
            @method('PATCH')
            <div class="sm:col-span-2">
                <h4 class="text-sm font-semibold text-slate-950">{{ __('app.festival_reassign_category') }}</h4>
                <p class="mt-1 text-xs text-slate-500">{{ __('app.festival_reassignment_copy') }}</p>
            </div>
            <label>
                <span class="crm-label">{{ __('app.festival_target_category') }}</span>
                <select name="festival_category_id" class="crm-field" required>
                    <option value="">{{ __('app.select_option') }}</option>
                    @foreach($reassignmentCategories as $reassignmentCategory)
                        <option value="{{ $reassignmentCategory->id }}">{{ $reassignmentCategory->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span class="crm-label">{{ __('app.festival_reassignment_reason') }}</span>
                <input name="reason" class="crm-field" maxlength="1000" required>
            </label>
            <div class="sm:col-span-2"><x-ui.button type="submit" size="sm" variant="secondary">{{ __('app.festival_reassign_category') }}</x-ui.button></div>
        </form>
    @endif
</section>
