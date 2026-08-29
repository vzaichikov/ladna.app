@extends('layouts.app')

@section('title', __('app.festival_media_report').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_media_report')" :copy="__('app.festival_media_report_copy')">
        <x-slot:actions>
            <x-ui.button type="button" data-festival-media-duplicates-open>
                <x-ui.icon name="sparkles" class="h-4 w-4" />{{ __('app.festival_media_duplicates_button') }}
            </x-ui.button>
            <x-ui.button :href="route('dashboard.accounts.festivals.applications', [$account, $edition])" variant="secondary">{{ __('app.back') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar
        :action="route('dashboard.accounts.festivals.applications.media-report', [$account, $edition])"
        :reset-href="route('dashboard.accounts.festivals.applications.media-report', [$account, $edition])"
        class="sm:grid-cols-2"
    >
        <label class="block min-w-0">
            <span class="crm-label">{{ __('app.search') }}</span>
            <input type="search" name="q" value="{{ $filters['q'] }}" class="crm-field min-h-11" placeholder="{{ __('app.festival_media_report_search_placeholder') }}">
        </label>
        <label class="block min-w-0">
            <span class="crm-label">{{ __('app.festival_category') }}</span>
            <select name="category" class="crm-field min-h-11">
                <option value="">{{ __('app.all') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category'] === (string) $category->id)>{{ $category->direction->name }} · {{ $category->name }}</option>
                @endforeach
            </select>
        </label>
    </x-ui.filter-bar>

    <div class="space-y-4">
        @forelse ($entries as $entry)
            <article class="min-w-0 rounded-2xl border border-stone-200 bg-white p-4 shadow-crm sm:p-5">
                <div class="flex min-w-0 flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs font-semibold text-slate-500">{{ $entry->code }}</span>
                            <span class="{{ $entry->status->badgeClass() }}">{{ __('app.festival_entry_status_'.$entry->status->value) }}</span>
                        </div>
                        <h2 class="mt-2 break-words text-lg font-semibold text-slate-950">{{ $entry->entry_name }}</h2>
                        <p class="mt-1 text-sm text-slate-700">{{ __('app.festival_applicant') }}: {{ $entry->portalUser->displayName() }}</p>
                        <p class="mt-1 text-sm text-slate-500">{{ $entry->category->direction->name }} · {{ $entry->category->name }}</p>
                    </div>
                    <x-ui.button :href="route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry])" size="sm" variant="secondary" class="shrink-0">
                        <x-ui.icon name="edit" class="h-4 w-4" />{{ __('app.festival_open_application') }}
                    </x-ui.button>
                </div>

                <dl class="mt-5 grid min-w-0 gap-3 lg:grid-cols-2">
                    @foreach ($entry->requirements as $requirement)
                        @php
                            $definition = $requirement->definition;
                            $submission = $requirement->latestSubmission;
                            $subjectLabel = $requirement->participant?->displayName()
                                ?? ($definition->subject_scope === \App\Enums\FestivalFieldScope::Registrant ? $entry->portalUser->displayName() : null);
                        @endphp
                        <div class="min-w-0 rounded-xl bg-slate-50 p-3 {{ $submission?->playbackKind() === 'video' ? 'lg:col-span-2' : '' }}">
                            <dt class="text-xs font-semibold text-slate-600">
                                {{ $definition->name }}@if($subjectLabel) · {{ $subjectLabel }}@endif
                            </dt>
                            <dd class="mt-2 min-w-0">
                                @if ($definition->input_type === \App\Enums\FestivalRequirementInputType::File)
                                    @if ($submission?->path && $submission->playbackKind() !== null)
                                        <x-festivals.submission-media :$account :$submission />
                                    @elseif ($submission?->path)
                                        <a href="{{ route('dashboard.accounts.festivals.submissions.download', [$account, $submission]) }}" class="break-all text-sm font-semibold text-brand-700 hover:text-brand-800">{{ __('app.download') }} · {{ $submission->original_name }}</a>
                                    @else
                                        <span class="text-sm text-slate-500">{{ __('app.not_set') }}</span>
                                    @endif
                                @elseif ($submission)
                                    <x-festivals.response-value :$definition :value="$submission->value_json['value'] ?? null" class="text-sm text-slate-800" />
                                @else
                                    <span class="text-sm text-slate-500">{{ __('app.not_set') }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </article>
        @empty
            @if (! $hasConfiguredFields)
                <x-ui.empty-state :title="__('app.festival_media_report_unconfigured')" icon="video">
                    <p>{{ __('app.festival_media_report_unconfigured_copy') }}</p>
                    @if ($workspacePermissions['manage'])
                        <x-ui.button :href="route('dashboard.accounts.festivals.settings.requirements', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.festival_registration_fields') }}</x-ui.button>
                    @endif
                </x-ui.empty-state>
            @elseif ($hasFilters)
                <x-ui.empty-state :title="__('app.no_data')" icon="search">
                    <p>{{ __('app.festival_media_report_filtered_empty') }}</p>
                    <x-ui.button :href="route('dashboard.accounts.festivals.applications.media-report', [$account, $edition])" variant="secondary" class="mt-3">{{ __('app.reset_filters') }}</x-ui.button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state :title="__('app.festival_media_report_empty')" icon="video">{{ __('app.festival_media_report_empty_copy') }}</x-ui.empty-state>
            @endif
        @endforelse
    </div>

    <div>{{ $entries->links() }}</div>

    <div
        class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/60 p-3 backdrop-blur-sm sm:p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="festival-media-duplicates-title"
        aria-describedby="festival-media-duplicates-copy"
        data-festival-media-duplicates-modal
        data-endpoint="{{ route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]) }}"
        data-csrf-token="{{ csrf_token() }}"
        data-loading-message="{{ __('app.festival_media_duplicates_loading') }}"
        data-summary-template="{{ __('app.festival_media_duplicates_checked') }}"
        data-group-template="{{ __('app.festival_media_duplicates_group') }}"
        data-insufficient-message="{{ __('app.festival_media_duplicates_insufficient_copy') }}"
        data-error-message="{{ __('app.festival_media_duplicates_error') }}"
    >
        <div class="max-h-[calc(100dvh-1.5rem)] w-full max-w-4xl overflow-y-auto rounded-2xl border border-stone-200 bg-white p-4 shadow-2xl sm:max-h-[calc(100dvh-2rem)] sm:p-6" tabindex="-1" data-festival-media-duplicates-panel>
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                            <x-ui.icon name="sparkles" class="h-5 w-5" />
                        </span>
                        <h2 id="festival-media-duplicates-title" class="text-xl font-semibold text-slate-950">{{ __('app.festival_media_duplicates_title') }}</h2>
                    </div>
                    <p id="festival-media-duplicates-copy" class="mt-3 text-sm leading-6 text-slate-600">{{ __('app.festival_media_duplicates_copy') }}</p>
                </div>
                <button type="button" class="shrink-0 rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 crm-focus" aria-label="{{ __('app.close') }}" data-festival-media-duplicates-dismiss>
                    <x-ui.icon name="x" class="h-5 w-5" />
                </button>
            </div>

            <p class="sr-only" role="status" aria-live="polite" data-festival-media-duplicates-announcement></p>

            <div class="mt-6" data-festival-media-duplicates-state="loading">
                <div class="flex min-h-40 flex-col items-center justify-center rounded-2xl border border-stone-200 bg-slate-50 p-6 text-center">
                    <span class="h-9 w-9 animate-spin rounded-full border-4 border-brand-100 border-t-brand-600" aria-hidden="true"></span>
                    <p class="mt-4 text-sm font-semibold text-slate-800">{{ __('app.festival_media_duplicates_loading') }}</p>
                </div>
            </div>

            <div class="mt-6 hidden" data-festival-media-duplicates-state="results">
                <p class="text-sm text-slate-600" data-festival-media-duplicates-summary></p>
                <div class="mt-4 space-y-4" data-festival-media-duplicates-results></div>
            </div>

            <div class="mt-6 hidden rounded-2xl border border-emerald-200 bg-emerald-50 p-5" data-festival-media-duplicates-state="empty">
                <h3 class="font-semibold text-emerald-950">{{ __('app.festival_media_duplicates_none') }}</h3>
                <p class="mt-1 text-sm leading-6 text-emerald-800">{{ __('app.festival_media_duplicates_none_copy') }}</p>
                <p class="mt-3 text-sm text-emerald-800" data-festival-media-duplicates-empty-summary></p>
            </div>

            <div class="mt-6 hidden rounded-2xl border border-amber-200 bg-amber-50 p-5" data-festival-media-duplicates-state="insufficient">
                <h3 class="font-semibold text-amber-950">{{ __('app.festival_media_duplicates_insufficient') }}</h3>
                <p class="mt-1 text-sm leading-6 text-amber-800">{{ __('app.festival_media_duplicates_insufficient_copy') }}</p>
                <p class="mt-3 text-sm text-amber-800" data-festival-media-duplicates-insufficient-summary></p>
            </div>

            <div class="mt-6 hidden rounded-2xl border border-rose-200 bg-rose-50 p-5" data-festival-media-duplicates-state="error">
                <h3 class="font-semibold text-rose-950">{{ __('app.festival_media_duplicates_error') }}</h3>
                <p class="mt-1 text-sm leading-6 text-rose-800" data-festival-media-duplicates-error></p>
            </div>

            <div class="mt-6 border-t border-stone-200 pt-4">
                <p class="text-xs leading-5 text-slate-500">{{ __('app.festival_media_duplicates_advisory') }}</p>
                <div class="mt-4 flex justify-end">
                    <x-ui.button type="button" variant="secondary" data-festival-media-duplicates-dismiss>{{ __('app.close') }}</x-ui.button>
                </div>
            </div>
        </div>
    </div>
</x-festivals.staff.workspace>
@endsection
