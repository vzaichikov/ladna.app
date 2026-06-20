<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $activityDirection->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $activityDirection->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<label class="block">
    <span class="crm-label">{{ __('app.description') }}</span>
    <textarea name="description" rows="3" class="crm-field">{{ old('description', $activityDirection->description) }}</textarea>
    @error('description') <span class="crm-help">{{ $message }}</span> @enderror
</label>
<div class="grid gap-4 sm:grid-cols-2">
    @php
        $colorValue = old('color', $activityDirection->color);
        $colorPickerValue = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $colorValue) ? $colorValue : '#A78AB9';
    @endphp
    <label class="block">
        <span class="crm-label">{{ __('app.color') }}</span>
        <span class="mt-2 flex items-center gap-3">
            <input
                type="color"
                value="{{ $colorPickerValue }}"
                class="h-11 w-14 cursor-pointer rounded-lg border border-stone-200 bg-white p-1"
                data-color-picker
            >
            <input
                name="color"
                value="{{ $colorValue }}"
                placeholder="#A78AB9"
                class="crm-field mt-0"
                data-color-value
            >
        </span>
        @error('color') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="flex items-center gap-3 pt-8 text-sm font-medium text-slate-700">
        <input type="hidden" name="is_active" value="0">
        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $activityDirection->is_active)) class="crm-checkbox">
        {{ __('app.active') }}
    </label>
</div>
