@extends('layouts.app')

@section('title', __('app.festival_tab_settings').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="crm-page-kicker">{{ __('app.festival_tab_settings') }}</p>
            <h2 class="mt-1 text-2xl font-semibold text-slate-950">{{ __('app.festival_settings_title') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('app.festival_settings_copy') }}</p>
        </div>
        @if ($workspacePermissions['manage'])
            <x-ui.button :href="route('dashboard.accounts.festivals.edit', [$account, $edition])" variant="secondary">{{ __('app.festival_edit_edition_details') }}</x-ui.button>
        @endif
    </div>

    @if ($workspacePermissions['manage'])
        <div class="grid gap-6 xl:grid-cols-2">
            <details class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm" open>
                <summary class="cursor-pointer text-xl font-semibold">{{ __('app.festival_categories') }}</summary>
                <div class="mt-4 space-y-2">
                    @forelse ($edition->categories as $category)
                        <div class="rounded-xl bg-slate-50 p-3"><strong>{{ $category->name }}</strong><span class="ml-2 text-sm text-slate-500">v{{ $category->version }} · {{ __('app.festival_workflow_'.$category->workflow->value) }} · {{ $category->min_members }}–{{ $category->max_members }}</span><span class="mt-1 block text-xs text-slate-500">{{ $category->options->pluck('label')->join(' · ') }}</span></div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_categories_empty') }}</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.categories.store', [$account, $edition]) }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label><span class="crm-label">{{ __('app.code') }}</span><input name="code" required class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.name') }}</span><input name="name" required class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.festival_workflow') }}</span><select name="workflow" class="crm-field">@foreach (\App\Enums\FestivalCategoryWorkflow::cases() as $workflow)<option value="{{ $workflow->value }}">{{ __('app.festival_workflow_'.$workflow->value) }}</option>@endforeach</select></label>
                    <div class="grid grid-cols-2 gap-2"><label><span class="crm-label">{{ __('app.minimum') }}</span><input type="number" name="min_members" min="1" value="1" class="crm-field"></label><label><span class="crm-label">{{ __('app.maximum') }}</span><input type="number" name="max_members" min="1" value="1" class="crm-field"></label></div>
                    <div class="grid grid-cols-2 gap-2"><label><span class="crm-label">{{ __('app.festival_min_age') }}</span><input type="number" name="min_age" min="0" class="crm-field"></label><label><span class="crm-label">{{ __('app.festival_max_age') }}</span><input type="number" name="max_age" min="0" class="crm-field"></label></div>
                    <div class="grid grid-cols-2 gap-2"><label><span class="crm-label">{{ __('app.festival_min_duration') }}</span><input type="number" name="min_duration_seconds" min="1" class="crm-field"></label><label><span class="crm-label">{{ __('app.festival_max_duration') }}</span><input type="number" name="max_duration_seconds" min="1" class="crm-field"></label></div>
                    @if ($edition->axes->isNotEmpty())
                        <fieldset class="sm:col-span-2"><legend class="crm-label">{{ __('app.festival_permitted_combination') }}</legend><div class="mt-2 grid gap-2 sm:grid-cols-2">@foreach ($edition->axes as $axis) @foreach ($axis->options as $option)<label class="flex items-center gap-2 rounded-lg border border-stone-200 p-3 text-sm"><input type="checkbox" name="option_ids[]" value="{{ $option->id }}" class="crm-checkbox"><span><strong>{{ $axis->name }}</strong> · {{ $option->label }}</span></label>@endforeach @endforeach</div></fieldset>
                    @endif
                    <div class="sm:col-span-2"><x-ui.button type="submit">{{ __('app.add') }}</x-ui.button></div>
                </form>
            </details>

            <details class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <summary class="cursor-pointer text-xl font-semibold">{{ __('app.festival_classification_axes') }}</summary>
                <div class="mt-4 space-y-2">
                    @forelse ($edition->axes as $axis)
                        <div class="rounded-xl bg-slate-50 p-3"><strong>{{ $axis->name }}</strong><span class="ml-2 text-xs text-slate-500">{{ $axis->kind }}</span><span class="mt-1 block text-sm text-slate-600">{{ $axis->options->pluck('label')->join(' · ') }}</span></div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_axes_empty') }}</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.axes.store', [$account, $edition]) }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <input name="code" required placeholder="{{ __('app.code') }}" class="crm-field">
                    <input name="name" required placeholder="{{ __('app.name') }}" class="crm-field">
                    <label><span class="crm-label">{{ __('app.festival_axis_kind') }}</span><select name="kind" class="crm-field"><option value="direction">Direction</option><option value="style">Style</option><option value="age">Age</option><option value="level">Level</option><option value="entry_format">Entry format</option><option value="custom">Custom</option></select></label>
                    <div><span class="crm-label">{{ __('app.festival_axis_options_help') }}</span><input type="hidden" name="options[0][code]" value="default"><input name="options[0][label]" required placeholder="{{ __('app.name') }}" class="crm-field"></div>
                    <div class="sm:col-span-2"><x-ui.button type="submit">{{ __('app.add') }}</x-ui.button></div>
                </form>
            </details>

            <details class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <summary class="cursor-pointer text-xl font-semibold">{{ __('app.festival_requirements') }}</summary>
                <div class="mt-4 space-y-2">
                    @forelse ($requirements as $requirement)
                        <div class="rounded-xl bg-slate-50 p-3"><strong>{{ $requirement->name }}</strong><span class="ml-2 text-sm text-slate-500">v{{ $requirement->version }} · {{ __('app.festival_requirement_'.$requirement->type->value) }} · {{ $requirement->category?->name ?? __('app.all') }}</span></div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_requirements_empty') }}</p>
                    @endforelse
                </div>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.requirements.store', [$account, $edition]) }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <label><span class="crm-label">{{ __('app.name') }}</span><input name="name" required class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.type') }}</span><select name="type" class="crm-field">@foreach (\App\Enums\FestivalRequirementType::cases() as $type)<option value="{{ $type->value }}">{{ __('app.festival_requirement_'.$type->value) }}</option>@endforeach</select></label>
                    <label><span class="crm-label">{{ __('app.festival_category') }}</span><select name="festival_category_id" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($edition->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                    <label><span class="crm-label">{{ __('app.festival_requirement_stage') }}</span><select name="stage" class="crm-field"><option value="qualification">{{ __('app.festival_qualification') }}</option><option value="final">{{ __('app.festival_final') }}</option></select></label>
                    <label><span class="crm-label">{{ __('app.festival_max_file_kb') }}</span><input type="number" name="max_size_kb" value="20480" min="1" class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.festival_due_at') }}</span><input type="datetime-local" name="due_at" class="crm-field"></label>
                    <div class="sm:col-span-2"><x-ui.button type="submit">{{ __('app.add') }}</x-ui.button></div>
                </form>
            </details>

            <details class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
                <summary class="cursor-pointer text-xl font-semibold">{{ __('app.festival_content_and_media') }}</summary>
                <div class="mt-4 grid gap-3 sm:grid-cols-3"><div class="rounded-xl bg-slate-50 p-3"><span class="text-sm text-slate-500">{{ __('app.festival_content_sections') }}</span><strong class="mt-1 block text-xl">{{ $edition->sections->count() }}</strong></div><div class="rounded-xl bg-slate-50 p-3"><span class="text-sm text-slate-500">{{ __('app.festival_documents') }}</span><strong class="mt-1 block text-xl">{{ $edition->documents->count() }}</strong></div><div class="rounded-xl bg-slate-50 p-3"><span class="text-sm text-slate-500">{{ __('app.festival_media') }}</span><strong class="mt-1 block text-xl">{{ $edition->media->count() }}</strong></div></div>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.content.store', [$account, $edition]) }}" class="mt-5 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <input name="key" required placeholder="{{ __('app.festival_content_key') }}" class="crm-field">
                    <input name="title" required placeholder="{{ __('app.title') }}" class="crm-field">
                    <select name="visibility" class="crm-field"><option value="public">Public</option><option value="portal">Portal</option><option value="staff">Staff</option></select>
                    <textarea name="body_html" rows="3" class="crm-field sm:col-span-2"></textarea>
                    <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
                </form>
                <form method="POST" enctype="multipart/form-data" action="{{ route('dashboard.accounts.festivals.documents.store', [$account, $edition]) }}" class="mt-6 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <input name="title" required placeholder="{{ __('app.title') }}" class="crm-field"><input name="kind" required value="rules" class="crm-field"><select name="visibility" class="crm-field"><option value="public">Public</option><option value="portal">Portal</option><option value="staff">Staff</option></select><input type="file" name="file" required class="crm-field"><x-ui.button type="submit">{{ __('app.upload') }}</x-ui.button>
                </form>
                <form method="POST" action="{{ route('dashboard.accounts.festivals.media.store', [$account, $edition]) }}" class="mt-6 grid gap-3 sm:grid-cols-2">
                    @csrf
                    <select name="kind" class="crm-field"><option value="image">Image</option><option value="video">Video</option></select><input type="url" name="external_url" required placeholder="{{ __('app.festival_media_url') }}" class="crm-field"><input name="alt_text" placeholder="Alt" class="crm-field"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_cover" value="1" class="crm-checkbox">Cover</label><x-ui.button type="submit">{{ __('app.add') }}</x-ui.button>
                </form>
            </details>
        </div>
    @endif

    @if ($workspacePermissions['finance'])
        <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm">
            <div class="flex items-center justify-between gap-4"><h2 class="text-xl font-semibold">{{ __('app.festival_fees') }}</h2><span class="text-sm text-slate-500">{{ $chargeDefinitions->count() }}</span></div>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($chargeDefinitions as $chargeDefinition)
                    <div class="rounded-xl bg-slate-50 p-4"><strong>{{ $chargeDefinition->name }}</strong><span class="mt-1 block text-sm text-slate-500">v{{ $chargeDefinition->version }} · {{ $chargeDefinition->kind }} · {{ $chargeDefinition->category?->name ?? __('app.all') }}</span><strong class="mt-2 block">{{ number_format($chargeDefinition->amount_cents / 100, 2) }} {{ $chargeDefinition->currency }}</strong></div>
                @endforeach
            </div>
            <form method="POST" action="{{ route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]) }}" class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @csrf
                <input name="name" required placeholder="{{ __('app.name') }}" class="crm-field">
                <select name="kind" class="crm-field"><option value="qualification">{{ __('app.festival_qualification') }}</option><option value="participation">{{ __('app.festival_participation') }}</option><option value="late">{{ __('app.festival_late_fee') }}</option><option value="custom">{{ __('app.custom') }}</option></select>
                <input type="number" name="amount_cents" min="0" required placeholder="{{ __('app.amount_cents') }}" class="crm-field">
                <select name="festival_category_id" class="crm-field"><option value="">{{ __('app.all') }}</option>@foreach ($edition->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>
                <div class="xl:col-span-4"><x-ui.button type="submit">{{ __('app.add') }}</x-ui.button></div>
            </form>
        </section>
    @endif
</x-festivals.staff.workspace>
@endsection
