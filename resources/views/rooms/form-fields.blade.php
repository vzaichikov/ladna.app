<label class="block">
    <span class="crm-label">{{ __('app.location') }}</span>
    <select name="location_id" required class="crm-field">
        @foreach ($locations as $location)
            <option value="{{ $location->id }}" @selected((int) old('location_id', $room->location_id) === $location->id)>{{ $location->name }}</option>
        @endforeach
    </select>
    @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $room->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $room->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<label class="block">
    <span class="crm-label">{{ __('app.description') }}</span>
    <textarea name="description" rows="3" class="crm-field">{{ old('description', $room->description) }}</textarea>
    @error('description') <span class="crm-help">{{ $message }}</span> @enderror
</label>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.capacity') }}</span>
        <input name="capacity" type="number" min="1" value="{{ old('capacity', $room->capacity) }}" class="crm-field">
        @error('capacity') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.color') }}</span>
        @php
            $colorValue = old('color', $room->color);
            $colorPickerValue = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $colorValue) ? $colorValue : '#38BDF8';
        @endphp
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
                placeholder="#38BDF8"
                class="crm-field mt-0"
                data-color-value
            >
        </span>
        @error('color') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <label class="flex items-center gap-3 pt-8 text-sm font-medium text-slate-700">
        <input type="hidden" name="is_active" value="0">
        <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $room->is_active)) class="crm-checkbox">
        {{ __('app.active') }}
    </label>
</div>

@if ($account->allowsRtspCameras())
    @php
        $cameraTest = session('rtsp_camera_test');
    @endphp

    <fieldset class="rounded-lg border border-stone-200 bg-slate-50 p-4">
        <legend class="crm-label px-1">{{ __('app.rtsp_camera') }}</legend>
        <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.rtsp_camera_help') }}</p>

        <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_auto] lg:items-end">
            <label class="block">
                <span class="crm-label">{{ __('app.rtsp_address') }}</span>
                <input name="rtsp_url" type="text" inputmode="url" value="{{ old('rtsp_url', $room->rtsp_url) }}" placeholder="rtsp://user:password@camera-host:554/stream" class="crm-field bg-white">
                @error('rtsp_url') <span class="crm-help">{{ $message }}</span> @enderror
            </label>

            <x-ui.button
                type="submit"
                variant="secondary"
                formaction="{{ route('dashboard.accounts.rooms.test-camera', $account) }}"
                formmethod="POST"
            >
                <x-ui.icon name="video" class="h-4 w-4" />
                {{ __('app.test') }}
            </x-ui.button>
        </div>

        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <label class="flex items-center gap-3 text-sm font-medium text-slate-700">
                <input type="hidden" name="rtsp_enabled" value="0">
                <input name="rtsp_enabled" type="checkbox" value="1" @checked(old('rtsp_enabled', $room->rtsp_enabled)) class="crm-checkbox">
                {{ __('app.enable_camera') }}
            </label>

            @if (is_array($cameraTest))
                <span class="{{ $cameraTest['ok'] ?? false ? 'text-emerald-700' : 'text-rose-700' }} text-sm font-semibold">
                    {{ $cameraTest['message'] ?? '' }}
                </span>
            @endif
        </div>
    </fieldset>
@endif
