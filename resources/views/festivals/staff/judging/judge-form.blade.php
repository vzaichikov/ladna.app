@extends('layouts.app')

@section('title', ($assignment->exists ? __('app.festival_edit_judge') : __('app.festival_add_judge')).' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header
        :title="$assignment->exists ? __('app.festival_edit_judge') : __('app.festival_add_judge')"
        :copy="__('app.festival_judge_form_copy')"
    />

    <x-ui.panel class="max-w-4xl">
        @php($selectedCategories = collect(old('category_ids', $assignment->exists ? $assignment->categories->modelKeys() : []))->map(fn ($id) => (int) $id)->all())
        <form method="POST" action="{{ $assignment->exists ? route('dashboard.accounts.festivals.judging.judges.update', [$account, $edition, $assignment]) : route('dashboard.accounts.festivals.judging.judges.store', [$account, $edition]) }}" class="space-y-6">
            @csrf
            @if ($assignment->exists)
                @method('PUT')
            @endif

            @if ($assignment->exists)
                <div class="rounded-xl border border-stone-200 bg-slate-50 p-4">
                    <p class="crm-label">{{ __('app.festival_judge_identity') }}</p>
                    <p class="mt-1 font-semibold text-slate-950">
                        @if ($assignment->user)
                            {{ __('app.festival_staff_identity') }} · {{ $assignment->user->name }} · {{ $assignment->user->email }}
                        @else
                            {{ __('app.festival_guest_identity') }} · {{ $assignment->portalUser?->displayName() }} · {{ $assignment->portalUser?->email }}
                        @endif
                    </p>
                    <p class="mt-2 text-sm text-slate-600">{{ __('app.festival_judge_identity_immutable_copy') }}</p>
                </div>
            @else
                <div>
                    <div class="grid gap-5 md:grid-cols-2">
                        <label>
                            <span class="crm-label">{{ __('app.festival_staff_identity') }}</span>
                            <select name="user_id" class="crm-field">
                                <option value="">{{ __('app.festival_choose_staff_judge') }}</option>
                                @foreach ($staffUsers as $staffUser)
                                    <option value="{{ $staffUser->id }}" @selected((int) old('user_id') === $staffUser->id)>{{ $staffUser->name }} · {{ $staffUser->email }}</option>
                                @endforeach
                            </select>
                            @error('user_id') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                        <label>
                            <span class="crm-label">{{ __('app.festival_guest_identity') }}</span>
                            <select name="festival_portal_user_id" class="crm-field">
                                <option value="">{{ __('app.festival_choose_guest_judge') }}</option>
                                @foreach ($portalUsers as $portalUser)
                                    <option value="{{ $portalUser->id }}" @selected((int) old('festival_portal_user_id') === $portalUser->id)>{{ $portalUser->displayName() }} · {{ $portalUser->email }}</option>
                                @endforeach
                            </select>
                            @error('festival_portal_user_id') <span class="crm-help">{{ $message }}</span> @enderror
                        </label>
                    </div>
                    <p class="mt-2 text-sm text-slate-500">{{ __('app.festival_judge_identity_required') }}</p>
                </div>
            @endif

            <label class="block">
                <span class="crm-label">{{ __('app.festival_judge_display_name') }}</span>
                <input name="display_name" value="{{ old('display_name', $assignment->display_name) }}" maxlength="255" required class="crm-field">
                @error('display_name') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <fieldset>
                <legend class="crm-label">{{ __('app.festival_categories') }}</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($categories as $category)
                        <label class="flex items-center gap-2 rounded-lg border border-stone-200 p-3 text-sm text-slate-700">
                            <input type="checkbox" name="category_ids[]" value="{{ $category->id }}" @checked(in_array($category->id, $selectedCategories, true)) class="crm-checkbox">
                            {{ $category->name }}
                        </label>
                    @endforeach
                </div>
                @error('category_ids') <span class="crm-help">{{ $message }}</span> @enderror
                @error('category_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
            </fieldset>

            <div class="flex flex-wrap gap-x-6 gap-y-3">
                <input type="hidden" name="is_head_judge" value="0">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_head_judge" value="1" @checked(old('is_head_judge', $assignment->is_head_judge ?? false)) class="crm-checkbox">
                    {{ __('app.festival_head_judge') }}
                </label>
                <input type="hidden" name="is_active" value="0">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $assignment->is_active ?? true)) class="crm-checkbox">
                    {{ __('app.active') }}
                </label>
            </div>

            <div class="flex flex-wrap gap-2">
                <x-ui.button type="submit">
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ __('app.save') }}
                </x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition])" variant="secondary">{{ __('app.cancel') }}</x-ui.button>
            </div>
        </form>
    </x-ui.panel>
</x-festivals.staff.workspace>
@endsection
