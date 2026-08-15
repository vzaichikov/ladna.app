@extends('layouts.app')

@section('title', __('app.festival_media_report').' - '.$edition->title)

@section('content')
<x-festivals.staff.workspace :$account :$edition :permissions="$workspacePermissions">
    <x-ui.page-header :title="__('app.festival_media_report')" :copy="__('app.festival_media_report_copy')">
        <x-slot:actions>
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
</x-festivals.staff.workspace>
@endsection
