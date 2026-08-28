<label
    class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-stone-200 bg-white p-3 transition has-checked:border-brand-500 has-checked:bg-brand-50"
    data-festival-helper-option
    data-festival-helper-id="{{ $participant->id }}"
>
    <input
        type="checkbox"
        name="value[helper_ids][]"
        value="{{ $participant->id }}"
        @checked($selected ?? false)
        class="crm-checkbox"
        data-festival-helper-choice
    >
    @include('festivals.portal.team._avatar', ['participant' => $participant, 'account' => $account, 'class' => 'h-11 w-11'])
    <span class="min-w-0">
        <span class="block truncate font-semibold text-slate-900">{{ $participant->displayName() }}</span>
        <span class="block text-xs text-slate-500">{{ $participant->date_of_birth->format('d.m.Y') }}</span>
    </span>
</label>
