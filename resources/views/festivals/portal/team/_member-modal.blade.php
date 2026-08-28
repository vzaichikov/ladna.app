@php
    $mode = $mode ?? 'add';
    $participant = $participant ?? null;
    $open = $open ?? false;
    $showErrors = $showErrors ?? false;
    $fragmentContext = $fragmentContext ?? 'team';
    $memberTypeLocked = $memberTypeLocked ?? false;
    $modalTitle = $mode === 'edit'
        ? __('app.festival_team_edit_member')
        : __('app.festival_team_add_member');
    $formAction = $mode === 'edit' && $participant
        ? route('festival.portal.participants.update', [$account->slug, $participant])
        : route('festival.portal.participants.store', $account->slug);
@endphp

<div
    id="{{ $modalId }}"
    class="fixed inset-0 z-50 {{ $open ? 'flex' : 'hidden' }} items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm sm:p-5"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $modalId }}-title"
    aria-hidden="{{ $open ? 'false' : 'true' }}"
    data-festival-team-modal="{{ $mode }}"
    data-festival-team-modal-context="{{ $fragmentContext }}"
    data-open="{{ $open ? 'true' : 'false' }}"
>
    <div class="max-h-[calc(100vh-1.5rem)] w-full max-w-2xl overflow-y-auto rounded-2xl border border-stone-200 bg-white shadow-2xl sm:max-h-[calc(100vh-2.5rem)]">
        <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-stone-200 bg-white px-5 py-4 sm:px-6">
            <h2 id="{{ $modalId }}-title" class="text-xl font-semibold text-slate-950">{{ $modalTitle }}</h2>
            <button type="button" class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 crm-focus" aria-label="{{ __('app.close') }}" data-festival-team-modal-close>
                <x-ui.icon name="x" class="h-5 w-5" />
            </button>
        </div>

        <form
            method="POST"
            enctype="multipart/form-data"
            action="{{ $formAction }}"
            class="space-y-5 p-5 sm:p-6"
            data-async-form
            data-festival-team-form="{{ $mode }}"
            data-server-validation-scroll
        >
            @csrf
            @if($mode === 'edit')
                @method('PUT')
            @endif

            <div data-async-form-status data-error-message="{{ __('app.async_request_failed') }}" data-validation-message="{{ __('app.async_validation_failed') }}" class="hidden"></div>

            @include('festivals.portal.team._form-fields', [
                'participant' => $participant,
                'mode' => $mode,
                'fieldIdPrefix' => $modalId,
                'defaultMemberType' => $defaultMemberType ?? null,
                'fragmentContext' => $fragmentContext,
                'showErrors' => $showErrors,
                'memberTypeLocked' => $memberTypeLocked,
            ])

            <div class="flex flex-col-reverse gap-3 border-t border-stone-200 pt-5 sm:flex-row sm:justify-end">
                <x-ui.button :href="route('festival.portal.participants.index', $account->slug)" variant="secondary" data-festival-team-modal-close>{{ __('app.cancel') }}</x-ui.button>
                <x-ui.button type="submit">
                    <x-ui.icon name="save" class="h-4 w-4" />
                    {{ $mode === 'edit' ? __('app.save') : __('app.festival_team_add_member') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>
