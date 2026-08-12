@props([
    'templateKey',
    'template',
    'name',
    'type' => 'radio',
    'checked' => false,
    'disabled' => false,
    'effective' => false,
])

@php($inputId = 'festival-template-'.preg_replace('/[^a-z0-9_-]/', '-', strtolower($name.'-'.$templateKey)))

<label for="{{ $inputId }}" @class([
    'group relative overflow-hidden rounded-xl border bg-white shadow-sm transition',
    'cursor-pointer hover:-translate-y-0.5 hover:border-violet-crm-500 hover:shadow-crm' => ! $disabled,
    'cursor-not-allowed opacity-75' => $disabled,
    'border-violet-crm-500 ring-2 ring-violet-crm-100' => $checked,
    'border-stone-200' => ! $checked,
])>
    <img
        src="{{ asset($template['thumbnail']) }}"
        alt=""
        class="aspect-video w-full border-b border-stone-200 bg-stone-100 object-cover"
    >
    <span class="flex items-center gap-3 p-4">
        <input
            id="{{ $inputId }}"
            type="{{ $type }}"
            name="{{ $name }}"
            value="{{ $templateKey }}"
            class="{{ $type === 'checkbox' ? 'crm-checkbox' : 'crm-radio' }}"
            @checked($checked)
            @disabled($disabled)
        >
        <span class="min-w-0 flex-1">
            <span class="block font-semibold text-slate-950">{{ __($template['name_key']) }}</span>
            @if ($templateKey === \App\Support\Festivals\FestivalLandingRegistry::DEFAULT_TEMPLATE)
                <span class="mt-0.5 block text-xs text-slate-500">{{ __('app.festival_landing_template_general_help') }}</span>
            @endif
        </span>
        @if ($effective)
            <span class="crm-status-active">{{ __('app.effective') }}</span>
        @endif
    </span>
</label>
