@php
    $participant = $participant ?? null;
    $showErrors = $showErrors ?? false;
    $memberTypeLocked = $memberTypeLocked ?? false;
    $fragmentContext = $fragmentContext ?? 'team';
    $fallbackMemberType = $participant?->member_type?->value
        ?? ($defaultMemberType instanceof \App\Enums\FestivalTeamMemberType
            ? $defaultMemberType->value
            : (string) ($defaultMemberType ?? ''));
    $selectedMemberType = $showErrors ? old('member_type', $fallbackMemberType) : $fallbackMemberType;
    $fieldValue = static fn (string $name, mixed $fallback = null): mixed => $showErrors ? old($name, $fallback) : $fallback;
    $hasPhoto = filled($participant?->resolvedPhotoPath());
@endphp

<input type="hidden" name="fragment_context" value="{{ $fragmentContext }}">
<input type="hidden" name="team_form_mode" value="{{ $mode }}">
<input type="hidden" name="team_participant_id" value="{{ $participant?->id }}" data-festival-team-participant-id>

<fieldset>
    <legend class="crm-label">{{ __('app.festival_team_member_type') }} <span class="text-rose-600" aria-hidden="true">*</span></legend>
    <div class="mt-2 grid gap-3 sm:grid-cols-2">
        @foreach(\App\Enums\FestivalTeamMemberType::cases() as $memberType)
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-stone-200 p-4 transition has-checked:border-brand-500 has-checked:bg-brand-50 has-disabled:cursor-not-allowed has-disabled:opacity-70">
                <input
                    type="radio"
                    name="member_type"
                    value="{{ $memberType->value }}"
                    @checked($selectedMemberType === $memberType->value)
                    @disabled($memberTypeLocked)
                    required
                    class="crm-radio mt-0.5"
                    data-festival-team-member-type
                >
                <span>
                    <span class="block font-semibold text-slate-900">{{ __('app.festival_team_member_type_'.$memberType->value) }}</span>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.festival_team_member_type_'.$memberType->value.'_help') }}</span>
                </span>
            </label>
        @endforeach
    </div>
    <input
        type="hidden"
        name="member_type"
        value="{{ $selectedMemberType }}"
        @disabled(! $memberTypeLocked)
        data-festival-team-member-type-locked-input
    >
    <p class="mt-2 {{ $memberTypeLocked ? '' : 'hidden' }} text-sm font-medium text-amber-800" data-festival-team-member-type-lock-note>
        {{ __('app.festival_team_member_type_locked') }}
    </p>
    @if($showErrors)<x-ui.field-error name="member_type" />@endif
</fieldset>

<div class="grid gap-4 sm:grid-cols-2">
    <label for="{{ $fieldIdPrefix }}-first-name">
        <span class="crm-label">{{ __('app.first_name') }} <span class="text-rose-600" aria-hidden="true">*</span></span>
        <input id="{{ $fieldIdPrefix }}-first-name" name="first_name" value="{{ $fieldValue('first_name', $participant?->first_name) }}" required maxlength="255" class="crm-field" autocomplete="off">
        @if($showErrors)<x-ui.field-error name="first_name" />@endif
    </label>
    <label for="{{ $fieldIdPrefix }}-last-name">
        <span class="crm-label">{{ __('app.last_name') }} <span class="text-rose-600" aria-hidden="true">*</span></span>
        <input id="{{ $fieldIdPrefix }}-last-name" name="last_name" value="{{ $fieldValue('last_name', $participant?->last_name) }}" required maxlength="255" class="crm-field" autocomplete="off">
        @if($showErrors)<x-ui.field-error name="last_name" />@endif
    </label>
    <label for="{{ $fieldIdPrefix }}-patronymic">
        <span class="crm-label">{{ __('app.patronymic') }}</span>
        <input id="{{ $fieldIdPrefix }}-patronymic" name="patronymic" value="{{ $fieldValue('patronymic', $participant?->patronymic) }}" maxlength="255" class="crm-field" autocomplete="off">
        @if($showErrors)<x-ui.field-error name="patronymic" />@endif
    </label>
    <label for="{{ $fieldIdPrefix }}-date-of-birth">
        <span class="crm-label">{{ __('app.date_of_birth') }} <span class="text-rose-600" aria-hidden="true">*</span></span>
        <input id="{{ $fieldIdPrefix }}-date-of-birth" type="date" name="date_of_birth" value="{{ $fieldValue('date_of_birth', $participant?->date_of_birth?->format('Y-m-d')) }}" max="{{ now()->toDateString() }}" required class="crm-field">
        @if($showErrors)<x-ui.field-error name="date_of_birth" />@endif
    </label>
</div>

<label for="{{ $fieldIdPrefix }}-notes">
    <span class="crm-label">{{ __('app.notes') }}</span>
    <textarea id="{{ $fieldIdPrefix }}-notes" name="notes" rows="3" maxlength="3000" class="crm-field">{{ $fieldValue('notes', $participant?->notes) }}</textarea>
    @if($showErrors)<x-ui.field-error name="notes" />@endif
</label>

<div>
    <label for="{{ $fieldIdPrefix }}-photo" class="crm-label">{{ __('app.photo') }}</label>
    <input id="{{ $fieldIdPrefix }}-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="crm-field">
    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.festival_photo_help') }}</p>
    @if($showErrors)<x-ui.field-error name="photo" />@endif

    @if($mode === 'edit')
        <label class="mt-3 {{ $hasPhoto ? 'flex' : 'hidden' }} items-center gap-2 text-sm text-slate-700" data-festival-team-remove-photo-container>
            <input type="checkbox" name="remove_photo" value="1" @checked($showErrors && old('remove_photo')) @disabled(! $hasPhoto) class="crm-checkbox">
            {{ __('app.festival_remove_photo') }}
        </label>
    @endif
</div>
