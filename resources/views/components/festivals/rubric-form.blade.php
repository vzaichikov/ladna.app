@props(['account', 'edition', 'rubric' => null])
@php
    $editing = $rubric?->exists;
    $sections = old('sections', $editing
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
            'criteria' => [['name' => __('app.festival_execution'), 'max_score' => 10, 'weight' => 1]],
        ]]);
@endphp
<form method="POST" action="{{ $editing ? route('dashboard.accounts.festivals.rubrics.update', [$account, $edition, $rubric]) : route('dashboard.accounts.festivals.rubrics.store', [$account, $edition]) }}" class="space-y-3">
    @csrf
    @if($editing) @method('PUT') @endif
    <input name="name" value="{{ old('name', $rubric?->name) }}" required placeholder="{{ __('app.name') }}" class="crm-field">
    <select name="festival_category_id" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($edition->categories as $category)<option value="{{ $category->id }}" @selected((int) old('festival_category_id', $rubric?->festival_category_id) === $category->id)>{{ $category->name }}</option>@endforeach</select>
    @foreach($sections as $sectionIndex => $section)
        <fieldset class="space-y-3 rounded-xl border border-stone-200 p-3">
            <input name="sections[{{ $sectionIndex }}][name]" value="{{ $section['name'] ?? '' }}" required class="crm-field" aria-label="{{ __('app.festival_rubric_section') }}">
            <input type="number" step="0.01" name="sections[{{ $sectionIndex }}][weight]" value="{{ $section['weight'] ?? 1 }}" required class="crm-field" aria-label="{{ __('app.weight') }}">
            @foreach(($section['criteria'] ?? []) as $criterionIndex => $criterion)
                <div class="grid gap-2 sm:grid-cols-[1fr_7rem_7rem]">
                    <input name="sections[{{ $sectionIndex }}][criteria][{{ $criterionIndex }}][name]" value="{{ $criterion['name'] ?? '' }}" required class="crm-field" aria-label="{{ __('app.festival_rubric_criterion') }}">
                    <input type="number" step="0.01" name="sections[{{ $sectionIndex }}][criteria][{{ $criterionIndex }}][max_score]" value="{{ $criterion['max_score'] ?? 10 }}" required class="crm-field" aria-label="{{ __('app.maximum') }}">
                    <input type="number" step="0.01" name="sections[{{ $sectionIndex }}][criteria][{{ $criterionIndex }}][weight]" value="{{ $criterion['weight'] ?? 1 }}" required class="crm-field" aria-label="{{ __('app.weight') }}">
                </div>
            @endforeach
        </fieldset>
    @endforeach
    <input type="hidden" name="is_active" value="0">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rubric?->is_active ?? true)) class="crm-checkbox">{{ __('app.active') }}</label>
    <x-ui.button type="submit">{{ $editing ? __('app.save') : __('app.add') }}</x-ui.button>
</form>
