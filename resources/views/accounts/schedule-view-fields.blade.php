@php
    $selectedPublicScheduleView = old('public_schedule_view', $account->publicScheduleView()->value());
@endphp

<input type="hidden" name="brand_tab" value="schedule_view">
<input type="hidden" name="name" value="{{ $account->name }}">
<input type="hidden" name="slug" value="{{ $account->slug }}">
<input type="hidden" name="default_language" value="{{ $account->default_language }}">
<input type="hidden" name="country_code" value="{{ $account->country_code ?? 'UA' }}">
<input type="hidden" name="default_currency" value="{{ $account->default_currency }}">
<input type="hidden" name="brand_color" value="{{ $account->brand_color }}">
<input type="hidden" name="timezone" value="{{ $account->timezone }}">

<fieldset>
    <legend class="crm-label">{{ __('app.public_schedule_view') }}</legend>
    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.public_schedule_view_help') }}</p>

    <div class="mt-5 grid gap-4 md:grid-cols-2">
        @foreach ($publicScheduleViewOptions as $option)
            @php
                $isSelected = $selectedPublicScheduleView === $option['value'];
            @endphp
            <label class="group block cursor-pointer rounded-xl border bg-white p-4 shadow-xs transition {{ $isSelected ? 'border-violet-crm-600 ring-2 ring-violet-crm-100' : 'border-stone-200 hover:border-violet-crm-200' }}">
                <input
                    type="radio"
                    name="public_schedule_view"
                    value="{{ $option['value'] }}"
                    @checked($isSelected)
                    class="sr-only"
                >
                <span class="flex items-start justify-between gap-4">
                    <span>
                        <span class="block text-base font-semibold text-slate-950">{{ __($option['label_key']) }}</span>
                        <span class="mt-1 block text-sm leading-6 text-slate-500">{{ __($option['copy_key']) }}</span>
                    </span>
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border {{ $isSelected ? 'border-violet-crm-600 bg-violet-crm-600 text-white' : 'border-stone-300 bg-white text-transparent group-hover:border-violet-crm-300' }}">
                        <x-ui.icon name="check" class="h-3.5 w-3.5" />
                    </span>
                </span>

                <span class="mt-4 block overflow-hidden rounded-lg border border-stone-200 bg-slate-50 p-3">
                    <span class="block rounded-md border border-stone-200 bg-white p-3">
                        @if ($option['value'] === 'compact_booking')
                            <span class="mb-3 flex gap-1.5 overflow-hidden">
                                @foreach (range(0, 4) as $index)
                                    <span class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg {{ $index === 1 ? 'bg-violet-crm-600 text-white' : 'bg-stone-100 text-slate-500' }}">
                                        <span class="text-base font-semibold">{{ 17 + $index }}</span>
                                        <span class="text-[10px] font-semibold">{{ strtoupper(substr(now()->addDays($index)->translatedFormat('D'), 0, 2)) }}</span>
                                    </span>
                                @endforeach
                            </span>
                            <span class="grid gap-2">
                                <span class="h-14 rounded-lg border border-stone-200 bg-white"></span>
                                <span class="h-14 rounded-lg border border-stone-200 bg-white"></span>
                                <span class="h-14 rounded-lg border border-stone-200 bg-white"></span>
                            </span>
                        @else
                            <span class="mb-3 block h-10 rounded-lg bg-violet-crm-600"></span>
                            <span class="grid gap-2 sm:grid-cols-2">
                                <span class="h-20 rounded-lg border border-stone-200 bg-white"></span>
                                <span class="h-20 rounded-lg border border-stone-200 bg-white"></span>
                            </span>
                        @endif
                    </span>
                    <span class="mt-2 block text-xs font-medium text-slate-500">{{ __($option['preview_key']) }}</span>
                </span>
            </label>
        @endforeach
    </div>

    @error('public_schedule_view') <span class="crm-help">{{ $message }}</span> @enderror
</fieldset>
