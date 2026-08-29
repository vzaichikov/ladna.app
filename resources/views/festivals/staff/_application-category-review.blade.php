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

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ $category->direction->name }}</p>
            <h3 class="mt-1 font-semibold text-slate-950">{{ $category->name }}</h3>
            <dl class="mt-3 flex flex-wrap gap-2 text-xs text-slate-700">
                <x-festivals.category-limit-chips :category="$category" />
                @if($category->registration_closes_at)
                    <div class="rounded-full bg-slate-100 px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_registration_closes_at') }}</dt><dd>{{ __('app.festival_category_deadline_value', ['date' => $category->registration_closes_at->timezone($edition->timezone)->format('d.m.Y H:i'), 'timezone' => $edition->timezone]) }}</dd></div>
                @endif
            </dl>
        </div>
        @if($reassignmentCategories->isNotEmpty())
            <x-ui.button
                type="button"
                size="sm"
                variant="secondary"
                class="shrink-0 self-start"
                aria-haspopup="dialog"
                aria-controls="festival-category-modal-{{ $entry->id }}"
                data-festival-category-modal-open
            >
                <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                {{ __('app.festival_reassign_category') }}
            </x-ui.button>
        @endif
    </div>
    <div class="mt-4 border-t border-stone-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-950">{{ __('app.festival_category_requirements') }}</h4>
        @if($category->requirements_html)
            <div class="prose prose-slate mt-2 max-w-none text-sm">{!! $category->requirements_html !!}</div>
        @else
            <p class="mt-2 text-sm text-slate-500">{{ __('app.festival_category_requirements_none') }}</p>
        @endif
    </div>
    @if($reassignmentCategories->isNotEmpty())
        <div
            id="festival-category-modal-{{ $entry->id }}"
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="festival-category-modal-title-{{ $entry->id }}"
            aria-describedby="festival-category-modal-copy-{{ $entry->id }}"
            data-festival-category-modal
            data-open="{{ (string) old('category_reassignment_form') === '1' ? 'true' : 'false' }}"
        >
            <div class="max-h-[calc(100vh-2rem)] w-full max-w-xl overflow-y-auto rounded-2xl border border-stone-200 bg-white p-5 shadow-2xl sm:p-6" data-festival-category-modal-panel>
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 id="festival-category-modal-title-{{ $entry->id }}" class="text-xl font-semibold text-slate-950">{{ __('app.festival_reassign_category') }}</h2>
                        <p id="festival-category-modal-copy-{{ $entry->id }}" class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_reassignment_copy') }}</p>
                    </div>
                    <button type="button" class="shrink-0 rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 crm-focus" aria-label="{{ __('app.close') }}" data-festival-category-modal-close>
                        <x-ui.icon name="x" class="h-5 w-5" />
                    </button>
                </div>

                <form
                    method="POST"
                    action="{{ route('dashboard.accounts.festivals.entries.reassign-category', [$account, $edition, $entry]) }}"
                    class="mt-6 space-y-5"
                    data-async-form
                >
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="category_reassignment_form" value="1">
                    <label class="block">
                        <span class="crm-label">{{ __('app.festival_target_category') }}</span>
                        <select name="festival_category_id" class="crm-field" required @if((string) old('category_reassignment_form') === '1' && $errors->has('festival_category_id')) aria-invalid="true" @endif>
                            <option value="">{{ __('app.select_option') }}</option>
                            @foreach($reassignmentCategories as $reassignmentCategory)
                                <option value="{{ $reassignmentCategory->id }}" @selected((string) old('festival_category_id') === (string) $reassignmentCategory->id)>{{ $reassignmentCategory->name }}</option>
                            @endforeach
                        </select>
                        @if((string) old('category_reassignment_form') === '1')
                            @error('festival_category_id')<span class="mt-1 block text-sm text-rose-700">{{ $message }}</span>@enderror
                        @endif
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.festival_reassignment_reason') }}</span>
                        <input name="reason" value="{{ (string) old('category_reassignment_form') === '1' ? old('reason') : '' }}" class="crm-field" maxlength="2000" required @if((string) old('category_reassignment_form') === '1' && $errors->has('reason')) aria-invalid="true" @endif>
                        @if((string) old('category_reassignment_form') === '1')
                            @error('reason')<span class="mt-1 block text-sm text-rose-700">{{ $message }}</span>@enderror
                        @endif
                    </label>
                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <x-ui.button type="button" variant="secondary" data-festival-category-modal-close>{{ __('app.cancel') }}</x-ui.button>
                        <x-ui.button type="submit">
                            <x-ui.icon name="refresh-cw" class="h-4 w-4" />
                            {{ __('app.festival_reassign_category') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</section>
