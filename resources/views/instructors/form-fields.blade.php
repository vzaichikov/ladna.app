<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.name') }}</span>
        <input name="name" value="{{ old('name', $instructor->name) }}" required class="crm-field">
        @error('name') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.slug') }}</span>
        <input name="slug" value="{{ old('slug', $instructor->slug) }}" class="crm-field">
        @error('slug') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <label class="block">
        <span class="crm-label">{{ __('app.email') }}</span>
        <input name="email" type="email" value="{{ old('email', $instructor->email) }}" class="crm-field">
        @error('email') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
    <label class="block">
        <span class="crm-label">{{ __('app.phone') }}</span>
        <input name="phone" value="{{ old('phone', $instructor->phone) }}" class="crm-field">
        @error('phone') <span class="crm-help">{{ $message }}</span> @enderror
    </label>
</div>
<label class="block">
    <span class="crm-label">Bio</span>
    <textarea name="bio" rows="3" class="crm-field">{{ old('bio', $instructor->bio) }}</textarea>
    @error('bio') <span class="crm-help">{{ $message }}</span> @enderror
</label>
<label class="flex items-center gap-3 text-sm font-medium text-slate-700">
    <input type="hidden" name="is_active" value="0">
    <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $instructor->is_active)) class="crm-checkbox">
    {{ __('app.active') }}
</label>
