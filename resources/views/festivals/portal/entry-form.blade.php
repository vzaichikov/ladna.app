@extends('layouts.public')

@section('title', __('app.festival_performance').' - '.$edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-4xl">
        @include('festivals.portal._nav')
        <header class="mt-8">
            <p class="text-sm font-semibold text-brand-700">{{ $edition->title }}</p>
            <h1 class="mt-1 text-3xl font-semibold sm:text-4xl">{{ $entry->exists ? __('app.festival_edit_performance') : __('app.festival_new_performance') }}</h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">{{ __('app.festival_step_application_help') }}</p>
        </header>

        @if($errors->any())
            <div class="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ $entry->exists ? route('festival.portal.entries.update', [$account->slug, $entry]) : route('festival.portal.entries.store', [$account->slug, $edition->slug]) }}" class="mt-6 space-y-6">
            @csrf
            @if($entry->exists) @method('PUT') @endif

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <h2 class="text-xl font-semibold">{{ __('app.festival_choose_category') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $entry->exists ? __('app.festival_category_selected_copy') : __('app.festival_choose_category_copy') }}</p>

                @if($entry->exists)
                    <input type="hidden" name="festival_category_id" value="{{ $entry->festival_category_id }}">
                    <article class="mt-5 rounded-2xl border border-brand-300 bg-brand-50/60 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ $entry->category->direction->name }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-950">{{ $entry->category->name }}</h3>
                        <dl class="mt-3 flex flex-wrap gap-2 text-xs text-slate-700">
                            <div class="rounded-full bg-white px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_roster') }}</dt><dd>{{ __('app.festival_participants_range', ['min' => $entry->category->min_members, 'max' => $entry->category->max_members]) }}</dd></div>
                            @if($entry->category->min_age !== null || $entry->category->max_age !== null)
                                <div class="rounded-full bg-white px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_age_limits') }}</dt><dd>{{ __('app.festival_age_range', ['min' => $entry->category->min_age ?? '—', 'max' => $entry->category->max_age ?? '—']) }}</dd></div>
                            @endif
                            @if($entry->category->min_duration_seconds !== null || $entry->category->max_duration_seconds !== null)
                                <div class="rounded-full bg-white px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_performance_duration') }}</dt><dd>{{ __('app.festival_duration_range', ['min' => $entry->category->min_duration_seconds ?? '—', 'max' => $entry->category->max_duration_seconds ?? '—']) }}</dd></div>
                            @endif
                            @if($entry->category->registration_closes_at)
                                <div class="rounded-full bg-white px-3 py-1.5"><dt class="sr-only">{{ __('app.festival_registration_closes_at') }}</dt><dd>{{ __('app.festival_category_deadline_value', ['date' => $entry->category->registration_closes_at->timezone($edition->timezone)->format('d.m.Y H:i'), 'timezone' => $edition->timezone]) }}</dd></div>
                            @endif
                        </dl>
                        <div class="mt-4 border-t border-brand-200 pt-4">
                            <h4 class="text-sm font-semibold text-slate-950">{{ __('app.festival_category_requirements') }}</h4>
                            @if($entry->category->requirements_html)
                                <div class="prose prose-slate mt-2 max-w-none text-sm">{!! $entry->category->requirements_html !!}</div>
                            @else
                                <p class="mt-2 text-sm text-slate-500">{{ __('app.festival_category_requirements_none') }}</p>
                            @endif
                        </div>
                    </article>
                @else
                    <div class="mt-5 space-y-7">
                        @forelse($categories->groupBy('festival_direction_id') as $directionCategories)
                            <fieldset>
                                <legend class="text-base font-semibold text-slate-950">{{ $directionCategories->first()->direction->name }}</legend>
                                <div class="mt-3 grid gap-4 lg:grid-cols-2">
                                    @foreach($directionCategories as $category)
                                        @php
                                            $categoryInputId = 'festival-category-'.$category->id;
                                            $categoryDescriptionId = $categoryInputId.'-description';
                                        @endphp
                                        <div class="rounded-2xl border border-stone-200 bg-white p-4 transition has-checked:border-brand-500 has-checked:bg-brand-50/60 has-focus-visible:ring-2 has-focus-visible:ring-brand-500">
                                            <div class="flex items-start gap-3">
                                                <input id="{{ $categoryInputId }}" type="radio" name="festival_category_id" value="{{ $category->id }}" required aria-describedby="{{ $categoryDescriptionId }}" class="crm-radio mt-1" @checked((int) old('festival_category_id') === $category->id)>
                                                <label for="{{ $categoryInputId }}" class="min-w-0 cursor-pointer text-base font-semibold text-slate-950">{{ $category->name }}</label>
                                            </div>
                                            <div id="{{ $categoryDescriptionId }}" class="mt-3 pl-7">
                                                <dl class="flex flex-wrap gap-2 text-xs text-slate-700">
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
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </fieldset>
                        @empty
                            <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-950">{{ __('app.festival_categories_unavailable') }}</p>
                        @endforelse
                    </div>
                @endif
                @error('festival_category_id') <span class="crm-help mt-3">{{ $message }}</span> @enderror
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <h2 class="text-xl font-semibold">{{ __('app.festival_performance_details') }}</h2>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label><span class="crm-label">{{ __('app.festival_entry_name') }}</span><input name="entry_name" value="{{ old('entry_name', $entry->entry_name) }}" required class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.festival_act_title') }}</span><input name="act_title" value="{{ old('act_title', $entry->act_title) }}" class="crm-field"></label>
                    <label class="sm:col-span-2"><span class="crm-label">{{ __('app.description') }}</span><textarea name="act_description" rows="4" class="crm-field">{{ old('act_description', $entry->act_description) }}</textarea></label>
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <h2 class="text-xl font-semibold">{{ __('app.festival_profile_contacts') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $portalUser->displayName() }} · {{ $portalUser->email }}</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <label><span class="crm-label">{{ __('app.phone') }}</span><input name="profile_phone" value="{{ old('profile_phone', $portalUser->phone) }}" class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.city') }}</span><input name="profile_city" value="{{ old('profile_city', $portalUser->city) }}" class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.studio') }}</span><input name="profile_studio_name" value="{{ old('profile_studio_name', $portalUser->studio_name) }}" class="crm-field"></label>
                </div>
            </section>

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <div class="flex items-center justify-between gap-4"><h2 class="text-xl font-semibold">{{ __('app.festival_roster') }}</h2><a href="{{ route('festival.portal.participants.index', $account->slug) }}" class="text-sm font-semibold text-brand-700">{{ __('app.add') }}</a></div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @forelse($participants as $participant)
                        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-stone-200 p-4 has-checked:border-brand-500 has-checked:bg-brand-50"><input type="checkbox" name="participant_ids[]" value="{{ $participant->id }}" @checked(collect(old('participant_ids', $entry->exists ? $entry->participants->modelKeys() : []))->contains($participant->id)) class="crm-checkbox"><span><strong class="block">{{ $participant->displayName() }}</strong><span class="text-xs text-slate-500">{{ $participant->date_of_birth->format('d.m.Y') }}</span></span></label>
                    @empty
                        <p class="sm:col-span-2 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">{{ __('app.festival_participants_required') }}</p>
                    @endforelse
                </div>
            </section>

            <div class="flex justify-end"><x-ui.button type="submit" size="lg">{{ __('app.save_and_continue') }}</x-ui.button></div>
        </form>
    </div>
</main>
@endsection
