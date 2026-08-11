@props(['account', 'edition', 'rubric' => null, 'categories'])

@php
    $rubric ??= new \App\Models\FestivalRubric(['is_active' => true]);
    $editing = $rubric->exists;
    $defaultSections = $editing
        ? $rubric->sections->map(fn ($section) => [
            'name' => $section->name,
            'weight' => $section->weight,
            'criteria' => $section->criteria->map(fn ($criterion) => [
                'name' => $criterion->name,
                'max_score' => $criterion->max_score,
                'weight' => $criterion->weight,
            ])->values()->all(),
        ])->values()->all()
        : [[
            'name' => __('app.festival_technique'),
            'weight' => 1,
            'criteria' => [[
                'name' => __('app.festival_execution'),
                'max_score' => 10,
                'weight' => 1,
            ]],
        ]];
    $oldSections = old('sections');
    $sections = is_array($oldSections) ? $oldSections : $defaultSections;
@endphp

<form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.judging.criteria.update', [$account, $edition, $rubric]) : route('dashboard.accounts.festivals.judging.criteria.store', [$account, $edition]) }}" class="space-y-6">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    <div class="grid gap-5 md:grid-cols-2">
        <label>
            <span class="crm-label">{{ __('app.name') }}</span>
            <input name="name" value="{{ old('name', $rubric->name) }}" maxlength="255" required class="crm-field" placeholder="{{ __('app.festival_rubric_name_placeholder') }}">
            @error('name') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
        <label>
            <span class="crm-label">{{ __('app.festival_category') }}</span>
            <select name="festival_category_id" class="crm-field">
                <option value="">{{ __('app.festival_all_categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected((int) old('festival_category_id', $rubric->festival_category_id) === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
            @error('festival_category_id') <span class="crm-help">{{ $message }}</span> @enderror
        </label>
    </div>

    <div class="space-y-4">
        @foreach ($sections as $sectionIndex => $section)
            @php($section = is_array($section) ? $section : [])
            <fieldset class="space-y-4 rounded-xl border border-stone-200 p-4">
                <legend class="px-2 text-sm font-semibold text-slate-950">{{ __('app.festival_rubric_section_number', ['number' => $loop->iteration]) }}</legend>
                <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_9rem]">
                    <label>
                        <span class="crm-label">{{ __('app.festival_rubric_section') }}</span>
                        <input name="sections[{{ $sectionIndex }}][name]" value="{{ $section['name'] ?? '' }}" maxlength="255" required class="crm-field">
                        @error('sections.'.$sectionIndex.'.name') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                    <label>
                        <span class="crm-label">{{ __('app.weight') }}</span>
                        <input type="number" min="0.01" step="0.01" name="sections[{{ $sectionIndex }}][weight]" value="{{ $section['weight'] ?? 1 }}" required class="crm-field">
                        @error('sections.'.$sectionIndex.'.weight') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="space-y-3">
                    @foreach (is_array($section['criteria'] ?? null) ? $section['criteria'] : [] as $criterionIndex => $criterion)
                        @php($criterion = is_array($criterion) ? $criterion : [])
                        <div class="grid gap-3 rounded-lg bg-slate-50 p-3 sm:grid-cols-[minmax(0,1fr)_8rem_8rem]">
                            <label>
                                <span class="crm-label">{{ __('app.festival_rubric_criterion') }}</span>
                                <input name="sections[{{ $sectionIndex }}][criteria][{{ $criterionIndex }}][name]" value="{{ $criterion['name'] ?? '' }}" maxlength="255" required class="crm-field">
                                @error('sections.'.$sectionIndex.'.criteria.'.$criterionIndex.'.name') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                            <label>
                                <span class="crm-label">{{ __('app.maximum') }}</span>
                                <input type="number" min="0.01" step="0.01" name="sections[{{ $sectionIndex }}][criteria][{{ $criterionIndex }}][max_score]" value="{{ $criterion['max_score'] ?? 10 }}" required class="crm-field">
                                @error('sections.'.$sectionIndex.'.criteria.'.$criterionIndex.'.max_score') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                            <label>
                                <span class="crm-label">{{ __('app.weight') }}</span>
                                <input type="number" min="0.01" step="0.01" name="sections[{{ $sectionIndex }}][criteria][{{ $criterionIndex }}][weight]" value="{{ $criterion['weight'] ?? 1 }}" required class="crm-field">
                                @error('sections.'.$sectionIndex.'.criteria.'.$criterionIndex.'.weight') <span class="crm-help">{{ $message }}</span> @enderror
                            </label>
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    </div>

    <input type="hidden" name="is_active" value="0">
    <label class="flex items-center gap-2 text-sm text-slate-700">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rubric->is_active ?? true)) class="crm-checkbox">
        {{ __('app.active') }}
    </label>

    <div class="flex flex-wrap gap-2">
        <x-ui.button type="submit">
            <x-ui.icon name="save" class="h-4 w-4" />
            {{ __('app.save') }}
        </x-ui.button>
        <x-ui.button :href="route('dashboard.accounts.festivals.judging.criteria.index', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
    </div>
</form>
