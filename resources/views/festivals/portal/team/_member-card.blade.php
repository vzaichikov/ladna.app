@php
    $memberType = $participant->member_type ?? \App\Enums\FestivalTeamMemberType::Performer;
    $isHelper = $memberType === \App\Enums\FestivalTeamMemberType::Helper;
    $isInUse = ((int) ($participant->entries_count ?? 0)) > 0
        || ((int) ($participant->helper_requirements_count ?? 0)) > 0;
@endphp

<article class="flex min-w-0 flex-col gap-4 rounded-2xl border border-stone-200 bg-white p-4 shadow-crm sm:flex-row sm:items-center sm:p-5" data-festival-team-member data-festival-team-member-id="{{ $participant->id }}">
    @include('festivals.portal.team._avatar', ['participant' => $participant, 'account' => $account])

    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="min-w-0 truncate font-semibold text-slate-950">{{ $participant->displayName() }}</h3>
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $isHelper ? 'bg-amber-100 text-amber-900' : 'bg-brand-100 text-brand-800' }}">
                {{ __('app.festival_team_member_type_'.$memberType->value) }}
            </span>
            @if($participant->is_profile_owner)
                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{{ __('app.festival_participant_profile') }}</span>
            @endif
        </div>
        <p class="mt-1 text-sm text-slate-500">{{ __('app.date_of_birth') }}: {{ $participant->date_of_birth->format('d.m.Y') }}</p>
        @if(filled($participant->notes))
            <p class="mt-2 line-clamp-2 whitespace-pre-line text-sm leading-6 text-slate-600">{{ $participant->notes }}</p>
        @endif
    </div>

    <div class="flex shrink-0 items-center gap-2 self-end sm:self-center">
        @if($participant->is_profile_owner)
            <x-ui.action-button
                :href="route('festival.portal.profile.edit', $account->slug)"
                icon="edit"
                :label="__('app.edit_profile')"
            />
        @else
            <x-ui.action-button
                :href="route('festival.portal.participants.index', ['accountSlug' => $account->slug, 'edit' => $participant->id])"
                icon="edit"
                :label="__('app.edit')"
                data-festival-team-edit
                data-team-edit-id="{{ $participant->id }}"
                data-team-edit-url="{{ route('festival.portal.participants.update', [$account->slug, $participant]) }}"
                data-team-edit-first-name="{{ $participant->first_name }}"
                data-team-edit-last-name="{{ $participant->last_name }}"
                data-team-edit-patronymic="{{ $participant->patronymic }}"
                data-team-edit-date-of-birth="{{ $participant->date_of_birth->format('Y-m-d') }}"
                data-team-edit-notes="{{ $participant->notes }}"
                data-team-edit-member-type="{{ $memberType->value }}"
                data-team-edit-has-photo="{{ filled($participant->resolvedPhotoPath()) ? 'true' : 'false' }}"
                data-team-edit-member-type-locked="{{ $isInUse ? 'true' : 'false' }}"
            />

            <form
                method="POST"
                action="{{ route('festival.portal.participants.destroy', [$account->slug, $participant]) }}"
                data-async-form
                data-confirm-delete
                data-confirm-title="{{ __('app.festival_portal_remove_from_team') }}"
                data-confirm-body="{{ __('app.festival_archive_participant_copy') }}"
                data-confirm-accept="{{ __('app.festival_portal_remove_from_team') }}"
            >
                @csrf
                @method('DELETE')
                <input type="hidden" name="fragment_context" value="team">
                <x-ui.action-button
                    type="submit"
                    variant="danger"
                    icon="trash"
                    :label="__('app.festival_portal_remove_from_team')"
                    :disabled="$isInUse"
                    :title="$isInUse ? __('app.festival_participant_archive_block') : __('app.festival_portal_remove_from_team')"
                />
            </form>
        @endif
    </div>
</article>
