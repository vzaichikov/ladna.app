@props([
    'for',
    'label',
    'help',
])

<div {{ $attributes->class(['relative flex w-full min-w-0 items-center gap-1.5']) }} data-field-help>
    <label for="{{ $for }}" class="crm-label">{{ $label }}</label>
    <button
        type="button"
        class="crm-focus inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-stone-300 bg-white text-xs font-bold text-slate-600 shadow-xs transition hover:border-brand-400 hover:text-brand-700"
        aria-label="{{ __('app.field_help_open', ['field' => $label]) }}"
        aria-expanded="false"
        aria-controls="{{ $for }}-help"
        data-field-help-toggle
    >
        <span aria-hidden="true">?</span>
    </button>
    <span
        id="{{ $for }}-help"
        role="tooltip"
        class="absolute left-0 top-full z-50 mt-2 w-72 max-w-full rounded-xl border border-stone-200 bg-white p-3 text-xs font-normal leading-5 text-slate-600 shadow-xl"
        data-field-help-popover
        hidden
    >
        {{ $help }}
    </span>
</div>
