@props(['name'])

@error($name)
    <span {{ $attributes->class(['crm-help']) }} role="alert" data-field-error="{{ $name }}">{{ $message }}</span>
@enderror
