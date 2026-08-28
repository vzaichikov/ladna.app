@extends('layouts.festival-portal')

@section('title', __('app.festival_performance').' - '.$edition->title)

@section('content')
<main class="min-h-screen bg-canvas px-4 py-6 sm:px-5 sm:py-8">
    <div class="mx-auto max-w-6xl">
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
                <p class="mt-1 text-sm leading-6 text-slate-600">{{ $entry->exists && ! $canChangeCategory ? __('app.festival_category_selected_copy') : ($entry->exists ? __('app.festival_category_change_available_copy') : __('app.festival_choose_category_copy')) }}</p>

                @if($entry->exists && ! $canChangeCategory)
                    <input type="hidden" name="festival_category_id" value="{{ $entry->festival_category_id }}">
                    <article class="mt-5 rounded-2xl border border-brand-300 bg-brand-50/60 p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-700">{{ $entry->category->direction->name }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-slate-950">{{ $entry->category->name }}</h3>
                        <dl class="mt-3 flex flex-wrap gap-2 text-xs text-slate-700">
                            <x-festivals.category-limit-chips :category="$entry->category" surface="white" />
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
                                            $categoryIsCurrent = $entry->exists && $entry->festival_category_id === $category->id;
                                            $categoryIsFull = $category->applicationCapacityReached($category->capacity_occupying_entries_count);
                                            $categoryIsUnavailable = $categoryIsFull && ! $categoryIsCurrent;
                                        @endphp
                                        <div @class([
                                            'rounded-2xl border p-4 transition',
                                            'border-stone-300 bg-stone-100 opacity-75' => $categoryIsUnavailable,
                                            'border-stone-200 bg-white has-checked:border-brand-500 has-checked:bg-brand-50/60 has-focus-visible:ring-2 has-focus-visible:ring-brand-500' => ! $categoryIsUnavailable,
                                        ])>
                                            <div class="flex items-start gap-3">
                                                <input id="{{ $categoryInputId }}" type="radio" name="festival_category_id" value="{{ $category->id }}" required aria-describedby="{{ $categoryDescriptionId }}" class="crm-radio mt-1" @checked((int) old('festival_category_id', $entry->festival_category_id) === $category->id) @disabled($categoryIsUnavailable)>
                                                <label for="{{ $categoryInputId }}" @class(['min-w-0 text-base font-semibold', 'cursor-not-allowed text-slate-500' => $categoryIsUnavailable, 'cursor-pointer text-slate-950' => ! $categoryIsUnavailable])>{{ $category->name }}</label>
                                            </div>
                                            <div id="{{ $categoryDescriptionId }}" class="mt-3 pl-7">
                                                @if($categoryIsFull)
                                                    <p class="mb-3 text-sm font-semibold text-rose-700">{{ __('app.festival_category_full') }}</p>
                                                @endif
                                                <dl class="flex flex-wrap gap-2 text-xs text-slate-700">
                                                    <x-festivals.category-limit-chips :category="$category" />
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
                    <label><span class="crm-label">{{ __('app.festival_entry_name') }}</span><input name="entry_name" value="{{ old('entry_name', $entry->exists ? $entry->entry_name : $portalUser->suggestedEntryName()) }}" required class="crm-field"></label>
                    <label><span class="crm-label">{{ __('app.festival_act_title') }}</span><input name="act_title" value="{{ old('act_title', $entry->act_title) }}" class="crm-field"></label>
                    <label class="sm:col-span-2"><span class="crm-label">{{ __('app.description') }}</span><textarea name="act_description" rows="4" class="crm-field">{{ old('act_description', $entry->act_description) }}</textarea></label>
                </div>
            </section>

            @include('festivals.portal._entry-profile-summary')

            <section class="rounded-2xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-xl font-semibold">{{ __('app.festival_roster') }}</h2>
                    <a
                        href="{{ route('festival.portal.participants.index', ['accountSlug' => $account->slug, 'add' => 'performer']) }}"
                        class="inline-flex min-h-11 items-center text-sm font-semibold text-brand-700"
                        data-festival-team-add-open
                        data-festival-team-modal-target="festival-performer-add-modal"
                        data-team-member-type="performer"
                        data-festival-performer-add
                    >{{ __('app.add') }}</a>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2" data-festival-performer-options>
                    @forelse($participants as $participant)
                        @include('festivals.portal.team._performer-option', [
                            'account' => $account,
                            'participant' => $participant,
                            'selected' => collect(old('participant_ids', $entry->exists ? $entry->participants->modelKeys() : []))->contains($participant->id),
                        ])
                    @empty
                        <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 sm:col-span-2" data-festival-performer-empty>{{ __('app.festival_participants_required') }}</p>
                    @endforelse
                </div>
            </section>

            <div class="flex justify-end"><x-ui.button type="submit" size="lg">{{ __('app.save_and_continue') }}</x-ui.button></div>
        </form>

        @include('festivals.portal.team._member-modal', [
            'account' => $account,
            'modalId' => 'festival-performer-add-modal',
            'mode' => 'add',
            'defaultMemberType' => \App\Enums\FestivalTeamMemberType::Performer,
            'fragmentContext' => 'performer_selection',
            'open' => false,
            'showErrors' => false,
        ])

        <div
            id="festival-quick-profile-modal"
            class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/45 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="festival-quick-profile-title"
            data-festival-quick-profile-modal
            data-open="{{ $errors->hasAny(['first_name', 'last_name', 'city', 'studio_name']) ? 'true' : 'false' }}"
        >
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="festival-quick-profile-title" class="text-xl font-semibold text-slate-950">{{ __('app.edit_profile') }}</h2>
                    <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-900 crm-focus" aria-label="{{ __('app.close') }}" data-festival-quick-profile-close><x-ui.icon name="x" class="h-5 w-5" /></button>
                </div>

                <form method="POST" action="{{ route('festival.portal.profile.application.update', $account->slug) }}" class="mt-6 space-y-5" data-async-form data-festival-quick-profile-form>
                    @csrf
                    @method('PUT')
                    <div
                        data-async-form-status
                        data-error-message="{{ __('app.async_request_failed') }}"
                        data-validation-message="{{ __('app.async_validation_failed') }}"
                        class="hidden"
                        role="status"
                        aria-live="polite"
                    ></div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="block"><span class="crm-label">{{ __('app.first_name') }}</span><input name="first_name" value="{{ old('first_name', $portalUser->first_name) }}" required maxlength="255" autocomplete="given-name" class="crm-field"><x-ui.field-error name="first_name" /></label>
                        <label class="block"><span class="crm-label">{{ __('app.last_name') }}</span><input name="last_name" value="{{ old('last_name', $portalUser->last_name) }}" required maxlength="255" autocomplete="family-name" class="crm-field"><x-ui.field-error name="last_name" /></label>
                        <label class="block"><span class="crm-label">{{ __('app.city') }}</span><input name="city" value="{{ old('city', $portalUser->city) }}" required maxlength="255" autocomplete="address-level2" class="crm-field"><x-ui.field-error name="city" /></label>
                        <label class="block"><span class="crm-label">{{ __('app.festival_studio_school') }}</span><input name="studio_name" value="{{ old('studio_name', $portalUser->studio_name) }}" required maxlength="255" autocomplete="organization" class="crm-field"><x-ui.field-error name="studio_name" /></label>
                    </div>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <x-ui.button type="button" variant="secondary" data-festival-quick-profile-close>{{ __('app.cancel') }}</x-ui.button>
                        <x-ui.button type="submit">{{ __('app.save') }}</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>
@endsection
