@php
    [$fileRequirements, $otherRequirements] = ($workspacePermissions['registrations'] ? $entry->requirements : collect())->partition(
        fn ($requirement): bool => $requirement->definition->input_type === \App\Enums\FestivalRequirementInputType::File,
    );
@endphp

<div class="space-y-5">
    @if($workspacePermissions['registrations'])
        @include('festivals.staff._application-applicant-contacts', compact('account', 'edition', 'entry'))
    @endif

    @include('festivals.staff._application-category-review', [
        'account' => $account,
        'edition' => $edition,
        'entry' => $entry,
        'categories' => $categories,
        'canManageRegistrations' => $workspacePermissions['registrations'],
    ])

    <div class="grid items-start gap-5 xl:grid-cols-2">
        <div class="space-y-5">
            @if ($workspacePermissions['registrations'])
                @include('festivals.staff._application-step-review', compact('account', 'edition', 'entry', 'currentStep'))
            @endif

            @if ($workspacePermissions['finance'])
                @include('festivals.staff._application-charges', compact('account', 'edition', 'entry'))
            @endif

            @if ($workspacePermissions['registrations'])
                <section>
                    <h3 class="font-semibold text-slate-950">{{ __('app.files') }}</h3>
                    <div class="mt-3 space-y-3">
                        @forelse ($fileRequirements as $requirement)
                            @include('festivals.staff._application-requirement-review', compact('account', 'edition', 'requirement'))
                        @empty
                            <p class="text-sm text-slate-500">{{ __('app.festival_no_files') }}</p>
                        @endforelse
                    </div>
                </section>
            @endif
        </div>

        @if ($workspacePermissions['registrations'])
            <section>
                <h3 class="font-semibold text-slate-950">{{ __('app.festival_checklist') }}</h3>
                <div class="mt-3 space-y-3">
                    @forelse ($otherRequirements as $requirement)
                        @include('festivals.staff._application-requirement-review', compact('account', 'edition', 'requirement'))
                    @empty
                        <p class="text-sm text-slate-500">{{ __('app.festival_no_requirements') }}</p>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
</div>
