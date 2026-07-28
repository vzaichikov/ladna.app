@extends('layouts.app')

@section('title', ($event->exists ? $event->title : __('app.event_create')).' - '.$account->name)

@section('content')
@php
    $timezone = old('timezone', $event->timezone ?: ($account->timezone ?: config('app.timezone')));
    $venueKind = old('venue_kind', $event->venue_kind?->value ?? 'studio');
    $selectedLocationId = (int) old('location_id', $event->location_id);
    $selectedRooms = collect(old('room_ids', $event->exists ? $event->rooms->modelKeys() : []))->map(fn ($id) => (int) $id);
    $selectedLocation = $locations->firstWhere('id', $selectedLocationId);
    $publicPageIsAvailable = $event->exists && in_array($event->status, [\App\Enums\EventStatus::Published, \App\Enums\EventStatus::Cancelled], true);
    $publicEventUrl = $publicPageIsAvailable ? route('public.events.show', [$account->slug, $event->slug]) : null;
@endphp
<div class="mx-auto max-w-6xl space-y-6">
    <header class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <a href="{{ route('dashboard.accounts.events.index', $account) }}" class="crm-page-kicker inline-flex items-center gap-1 transition hover:text-brand-700">
                <span aria-hidden="true">←</span>
                {{ __('app.events') }}
            </a>
            <h1 class="crm-page-title mt-2">{{ $event->exists ? $event->title : __('app.event_create') }}</h1>
            <p class="crm-page-copy">{{ __('app.event_form_intro') }}</p>
        </div>
        @if ($event->exists)
            <div class="flex flex-wrap gap-2">
                @if ($publicEventUrl)
                    <x-ui.button :href="$publicEventUrl" variant="secondary" target="_blank" rel="noopener">
                        <x-ui.icon name="external-link" class="h-4 w-4" />
                        {{ __('app.event_public_page') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="secondary" data-copy-button data-copy-value="{{ $publicEventUrl }}" data-copy-success-label="{{ __('app.copied') }}">
                        <x-ui.icon name="copy" class="h-4 w-4" />
                        <span data-copy-label>{{ __('app.copy_link') }}</span>
                    </x-ui.button>
                @endif
                <x-ui.button :href="route('dashboard.accounts.events.orders.index', [$account, $event])" variant="secondary">
                    <x-ui.icon name="receipt-text" class="h-4 w-4" />
                    {{ __('app.event_orders') }}
                </x-ui.button>
                <x-ui.button :href="route('dashboard.accounts.events.scanner', [$account, $event])" variant="secondary">
                    <x-ui.icon name="scan-line" class="h-4 w-4" />
                    {{ __('app.event_scanner') }}
                </x-ui.button>
            </div>
        @endif
    </header>

    @if ($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
            <p class="font-semibold">{{ __('app.event_form_errors') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form
        method="POST"
        action="{{ $event->exists ? route('dashboard.accounts.events.update', [$account, $event]) : route('dashboard.accounts.events.store', $account) }}"
        enctype="multipart/form-data"
        class="space-y-6"
        data-event-form
    >
        @csrf
        @if ($event->exists)
            @method('PUT')
        @endif

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6" id="event-details">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.event_details') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.event_details_help') }}</p>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-2">
                <label class="block sm:col-span-2">
                    <span class="crm-label">{{ __('app.event_title') }}</span>
                    <input name="title" value="{{ old('title', $event->title) }}" required class="crm-field" autocomplete="off">
                    @error('title') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block sm:col-span-2">
                    <span class="crm-label">{{ __('app.event_summary') }}</span>
                    <textarea name="summary" rows="3" class="crm-field" maxlength="500">{{ old('summary', $event->summary) }}</textarea>
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.event_summary_help') }}</span>
                    @error('summary') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.starts_at') }}</span>
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $event->starts_at?->timezone($timezone)->format('Y-m-d\\TH:i')) }}" required class="crm-field">
                    @error('starts_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.ends_at') }}</span>
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $event->ends_at?->timezone($timezone)->format('Y-m-d\\TH:i')) }}" required class="crm-field">
                    @error('ends_at') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.timezone') }}</span>
                    <input name="timezone" value="{{ $timezone }}" required class="crm-field" autocomplete="off">
                    @error('timezone') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.event_capacity') }}</span>
                    <input type="number" min="1" max="1000000" name="capacity" value="{{ old('capacity', $event->capacity) }}" class="crm-field" inputmode="numeric">
                    <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.event_capacity_help') }}</span>
                    @error('capacity') <span class="crm-help">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6" id="event-venue">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.event_venue') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.event_venue_help') }}</p>
            </div>

            <fieldset class="mt-5">
                <legend class="sr-only">{{ __('app.event_venue') }}</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (['studio', 'external'] as $kind)
                        <label class="cursor-pointer rounded-xl border border-stone-200 bg-white p-4 transition hover:border-brand-100 hover:bg-brand-50 has-checked:border-brand-600 has-checked:bg-brand-50 has-checked:ring-1 has-checked:ring-brand-600 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500 has-[:focus-visible]:ring-offset-2">
                            <span class="flex items-start gap-3">
                                <input type="radio" name="venue_kind" value="{{ $kind }}" @checked($venueKind === $kind) class="crm-radio mt-1">
                                <span>
                                    <span class="block font-semibold text-slate-950">{{ __('app.event_venue_'.$kind) }}</span>
                                    <span class="mt-1 block text-sm leading-5 text-slate-500">{{ __('app.event_venue_'.$kind.'_help') }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('venue_kind') <span class="crm-help">{{ $message }}</span> @enderror
            </fieldset>

            <div
                class="{{ $venueKind === 'studio' ? '' : 'hidden' }} mt-5 rounded-xl border border-stone-200 bg-slate-50 p-4 sm:p-5"
                data-event-venue-fields="studio"
            >
                <div class="grid gap-5 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                    <label class="block">
                        <span class="crm-label">{{ __('app.location') }}</span>
                        <select name="location_id" class="crm-field" data-event-location @disabled($venueKind !== 'studio')>
                            <option value="">{{ __('app.select') }}</option>
                            @foreach ($locations as $location)
                                <option value="{{ $location->id }}" @selected($selectedLocationId === $location->id)>{{ $location->name }}</option>
                            @endforeach
                        </select>
                        <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.event_location_help') }}</span>
                        @error('location_id') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <fieldset>
                        <legend class="crm-label">{{ __('app.rooms') }}</legend>
                        <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('app.event_rooms_help') }}</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2" data-event-room-options>
                            @foreach ($locations as $location)
                                @foreach ($location->rooms as $room)
                                    @php
                                        $roomIsVisible = $venueKind === 'studio' && $selectedLocationId === $location->id;
                                    @endphp
                                    <label
                                        class="{{ $roomIsVisible ? 'flex' : 'hidden' }} cursor-pointer items-center gap-3 rounded-lg border border-stone-200 bg-white px-3 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-100 has-checked:border-brand-500 has-checked:bg-brand-50"
                                        data-event-room-card
                                        data-location-id="{{ $location->id }}"
                                    >
                                        <input
                                            type="checkbox"
                                            name="room_ids[]"
                                            value="{{ $room->id }}"
                                            @checked($selectedRooms->contains($room->id))
                                            @disabled(! $roomIsVisible)
                                            class="crm-checkbox"
                                        >
                                        <span class="min-w-0">
                                            <span class="block truncate text-slate-950">{{ $room->name }}</span>
                                            <span class="mt-0.5 block truncate text-xs text-slate-500">{{ $location->name }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            @endforeach
                        </div>
                        <div
                            class="{{ $selectedLocationId > 0 && $selectedLocation?->rooms->isNotEmpty() ? 'hidden' : '' }} mt-3 rounded-lg border border-dashed border-stone-300 bg-white px-4 py-3 text-sm text-slate-500"
                            data-event-room-empty
                            data-choose-location="{{ __('app.event_choose_location_for_rooms') }}"
                            data-no-rooms="{{ __('app.event_no_active_rooms_at_location') }}"
                        >
                            {{ __('app.event_choose_location_for_rooms') }}
                        </div>
                        @error('room_ids') <span class="crm-help">{{ $message }}</span> @enderror
                        @error('room_ids.*') <span class="crm-help">{{ $message }}</span> @enderror
                    </fieldset>
                </div>
            </div>

            <div
                class="{{ $venueKind === 'external' ? '' : 'hidden' }} mt-5 rounded-xl border border-stone-200 bg-slate-50 p-4 sm:p-5"
                data-event-venue-fields="external"
            >
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_external_venue_name') }}</span>
                        <input name="external_venue_name" value="{{ old('external_venue_name', $event->external_venue_name) }}" class="crm-field" @disabled($venueKind !== 'external')>
                        @error('external_venue_name') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="crm-label">{{ __('app.address') }}</span>
                        <input name="external_address" value="{{ old('external_address', $event->external_address) }}" class="crm-field" @disabled($venueKind !== 'external')>
                        @error('external_address') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="crm-label">{{ __('app.event_map_url') }}</span>
                        <input type="url" name="external_map_url" value="{{ old('external_map_url', $event->external_map_url) }}" class="crm-field" placeholder="https://maps.google.com/…" @disabled($venueKind !== 'external')>
                        @error('external_map_url') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="crm-label">{{ __('app.event_directions') }}</span>
                        <textarea name="external_directions" rows="3" class="crm-field" @disabled($venueKind !== 'external')>{{ old('external_directions', $event->external_directions) }}</textarea>
                        @error('external_directions') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6" id="event-landing">
            <div class="border-b border-stone-100 pb-5">
                <h2 class="text-xl font-semibold text-slate-950">{{ __('app.event_landing') }}</h2>
                <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.event_landing_help') }}</p>
            </div>

            <div class="mt-5 grid gap-5">
                <label class="block">
                    <span class="crm-label">{{ __('app.event_description') }}</span>
                    <textarea name="description_html" rows="8" class="crm-field" data-studio-rules-editor data-editor-height="300" data-placeholder="{{ __('app.event_description_placeholder') }}">{{ old('description_html', $event->description_html) }}</textarea>
                    @error('description_html') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="crm-label">{{ __('app.event_rules') }}</span>
                    <textarea name="rules_html" rows="5" class="crm-field" data-studio-rules-editor data-editor-height="220" data-placeholder="{{ __('app.event_rules_placeholder') }}">{{ old('rules_html', $event->rules_html) }}</textarea>
                    @error('rules_html') <span class="crm-help">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_cover') }}</span>
                        <input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.webp" class="crm-field">
                        <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.event_cover_help') }}</span>
                        @error('cover_image') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="crm-label">{{ __('app.event_gallery') }}</span>
                        <input type="file" name="gallery_images[]" multiple accept=".jpg,.jpeg,.png,.webp" class="crm-field">
                        <span class="mt-1 block text-xs leading-5 text-slate-500">{{ __('app.event_gallery_help') }}</span>
                        @error('gallery_images') <span class="crm-help">{{ $message }}</span> @enderror
                        @error('gallery_images.*') <span class="crm-help">{{ $message }}</span> @enderror
                    </label>
                </div>

                <fieldset>
                    <legend class="crm-label">{{ __('app.event_video_urls') }}</legend>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ __('app.event_video_urls_help') }}</p>
                    <div class="mt-1 grid gap-3 sm:grid-cols-3">
                        @for ($videoIndex = 0; $videoIndex < 3; $videoIndex++)
                            <label class="block">
                                <span class="sr-only">{{ __('app.event_video_urls') }} {{ $videoIndex + 1 }}</span>
                                <input type="url" name="video_urls[]" value="{{ old('video_urls.'.$videoIndex) }}" placeholder="https://youtube.com/…" class="crm-field">
                            </label>
                        @endfor
                    </div>
                    @error('video_urls') <span class="crm-help">{{ $message }}</span> @enderror
                    @error('video_urls.*') <span class="crm-help">{{ $message }}</span> @enderror
                    @if ($event->media?->where('kind', 'video')->isNotEmpty())
                        <div class="mt-3 space-y-1 text-xs text-slate-500">
                            @foreach ($event->media->where('kind', 'video') as $video)
                                <p class="break-all">{{ $video->external_url }}</p>
                            @endforeach
                        </div>
                    @endif
                </fieldset>

                @if ($event->isPublished())
                    <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        <input type="checkbox" name="confirm_material_change" value="1" class="crm-checkbox mt-0.5">
                        <span>{{ __('app.event_material_change_confirmation') }}</span>
                    </label>
                    @error('confirm_material_change') <span class="crm-help">{{ $message }}</span> @enderror
                @endif
            </div>
        </section>

        <div class="flex justify-end rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
            <x-ui.button type="submit">
                <x-ui.icon name="save" class="h-4 w-4" />
                {{ $event->exists ? __('app.save') : __('app.event_create') }}
            </x-ui.button>
        </div>
    </form>

    @if ($event->exists)
        <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-crm sm:p-6" id="event-tickets">
            <div class="flex flex-col gap-3 border-b border-stone-100 pb-5 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">{{ __('app.event_tickets') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.event_tickets_help', ['currency' => $event->currency]) }}</p>
                </div>
                <span class="crm-status-muted">{{ $event->currency }}</span>
            </div>

            <div class="mt-5 grid gap-3">
                @foreach ($event->ticketTypes as $type)
                    <details class="group rounded-xl border border-stone-200 bg-white open:border-brand-100 open:shadow-sm" data-event-ticket-type="{{ $type->id }}">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4">
                            <span class="min-w-0">
                                <strong class="block truncate text-slate-950">{{ $type->name }}</strong>
                                <span class="mt-1 block text-sm text-slate-500">
                                    {{ number_format($type->price_cents / 100, 2) }} {{ $event->currency }}
                                    · {{ $type->remainingQuantity() }}/{{ $type->inventory }} {{ __('app.event_available') }}
                                </span>
                            </span>
                            <x-ui.icon name="chevron-down" class="h-5 w-5 shrink-0 text-slate-400 transition group-open:rotate-180" />
                        </summary>

                        <div class="border-t border-stone-100 bg-slate-50 p-4 sm:p-5">
                            <form method="POST" action="{{ route('dashboard.accounts.events.ticket-types.update', [$account, $event, $type]) }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-event-ticket-form="update">
                                @csrf
                                @method('PUT')

                                <label class="block">
                                    <span class="crm-label">{{ __('app.name') }}</span>
                                    <input name="name" required value="{{ $type->name }}" class="crm-field">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.price') }} · {{ $event->currency }}</span>
                                    <input name="price" required value="{{ number_format($type->price_cents / 100, 2, '.', '') }}" class="crm-field" inputmode="decimal">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.event_inventory') }}</span>
                                    <input type="number" name="inventory" required min="1" value="{{ $type->inventory }}" class="crm-field">
                                </label>
                                <label class="block sm:col-span-2 lg:col-span-3">
                                    <span class="crm-label">{{ __('app.description') }}</span>
                                    <textarea name="description" rows="2" class="crm-field">{{ $type->description }}</textarea>
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.event_early_price') }} · {{ $event->currency }}</span>
                                    <input name="early_bird_price" value="{{ $type->early_bird_price_cents !== null ? number_format($type->early_bird_price_cents / 100, 2, '.', '') : '' }}" class="crm-field" inputmode="decimal">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.event_early_ends') }}</span>
                                    <input type="datetime-local" name="early_bird_ends_at" value="{{ $type->early_bird_ends_at?->timezone($timezone)->format('Y-m-d\\TH:i') }}" class="crm-field">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.event_early_quota') }}</span>
                                    <input type="number" name="early_bird_quota" min="1" value="{{ $type->early_bird_quota }}" class="crm-field">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.event_sales_starts') }}</span>
                                    <input type="datetime-local" name="sales_starts_at" value="{{ $type->sales_starts_at?->timezone($timezone)->format('Y-m-d\\TH:i') }}" class="crm-field">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.event_sales_ends') }}</span>
                                    <input type="datetime-local" name="sales_ends_at" value="{{ $type->sales_ends_at?->timezone($timezone)->format('Y-m-d\\TH:i') }}" class="crm-field">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.event_max_per_order') }}</span>
                                    <input type="number" name="max_per_order" required min="1" max="100" value="{{ $type->max_per_order }}" class="crm-field">
                                </label>
                                <label class="block">
                                    <span class="crm-label">{{ __('app.sort_order') }}</span>
                                    <input type="number" name="sort_order" required min="0" value="{{ $type->sort_order }}" class="crm-field">
                                </label>
                                <label class="flex items-center gap-3 pt-2 text-sm font-medium text-slate-700 sm:self-end sm:pb-3">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" @checked($type->is_active) class="crm-checkbox">
                                    {{ __('app.active') }}
                                </label>
                                <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-3">
                                    <x-ui.button type="submit">
                                        <x-ui.icon name="save" class="h-4 w-4" />
                                        {{ __('app.save') }}
                                    </x-ui.button>
                                </div>
                            </form>

                            @if ($type->orderItems()->doesntExist())
                                <form method="POST" action="{{ route('dashboard.accounts.events.ticket-types.destroy', [$account, $event, $type]) }}" class="mt-3">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="danger" size="sm">
                                        <x-ui.icon name="trash-2" class="h-4 w-4" />
                                        {{ __('app.delete') }}
                                    </x-ui.button>
                                </form>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>

            <details class="group mt-5 rounded-xl border border-dashed border-brand-100 bg-brand-50/60" data-event-add-ticket>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 p-4 font-semibold text-brand-700">
                    <span class="inline-flex items-center gap-2">
                        <x-ui.icon name="plus" class="h-4 w-4" />
                        {{ __('app.event_add_ticket_type') }}
                    </span>
                    <x-ui.icon name="chevron-down" class="h-5 w-5 transition group-open:rotate-180" />
                </summary>

                <form method="POST" action="{{ route('dashboard.accounts.events.ticket-types.store', [$account, $event]) }}" class="grid gap-4 border-t border-brand-100 p-4 sm:grid-cols-2 lg:grid-cols-3 sm:p-5" data-event-ticket-form="create">
                    @csrf
                    <label class="block">
                        <span class="crm-label">{{ __('app.name') }}</span>
                        <input name="name" required class="crm-field">
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.price') }} · {{ $event->currency }}</span>
                        <input name="price" required value="0.00" class="crm-field" inputmode="decimal">
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_inventory') }}</span>
                        <input type="number" name="inventory" required min="1" value="20" class="crm-field">
                    </label>
                    <label class="block sm:col-span-2 lg:col-span-3">
                        <span class="crm-label">{{ __('app.description') }}</span>
                        <textarea name="description" rows="2" class="crm-field"></textarea>
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_early_price') }} · {{ $event->currency }}</span>
                        <input name="early_bird_price" class="crm-field" inputmode="decimal">
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_early_ends') }}</span>
                        <input type="datetime-local" name="early_bird_ends_at" class="crm-field">
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_early_quota') }}</span>
                        <input type="number" name="early_bird_quota" min="1" class="crm-field">
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_sales_starts') }}</span>
                        <input type="datetime-local" name="sales_starts_at" class="crm-field">
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_sales_ends') }}</span>
                        <input type="datetime-local" name="sales_ends_at" class="crm-field">
                    </label>
                    <label class="block">
                        <span class="crm-label">{{ __('app.event_max_per_order') }}</span>
                        <input type="number" name="max_per_order" required min="1" max="100" value="10" class="crm-field">
                    </label>
                    <input type="hidden" name="sort_order" value="10">
                    <input type="hidden" name="is_active" value="1">
                    <div class="sm:col-span-2 lg:col-span-3">
                        <x-ui.button type="submit">
                            <x-ui.icon name="plus" class="h-4 w-4" />
                            {{ __('app.event_add_ticket_type') }}
                        </x-ui.button>
                    </div>
                </form>
            </details>
        </section>

        <section class="flex flex-wrap gap-3 rounded-xl border border-stone-200 bg-white p-4 shadow-crm">
            @if ($event->status === \App\Enums\EventStatus::Draft)
                <form method="POST" action="{{ route('dashboard.accounts.events.publish', [$account, $event]) }}">
                    @csrf
                    <x-ui.button type="submit">
                        <x-ui.icon name="send" class="h-4 w-4" />
                        {{ __('app.event_publish') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($event->status === \App\Enums\EventStatus::Published)
                <form method="POST" action="{{ route('dashboard.accounts.events.cancel', [$account, $event]) }}">
                    @csrf
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="circle-x" class="h-4 w-4" />
                        {{ __('app.event_cancel') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($event->status !== \App\Enums\EventStatus::Archived && ($event->status === \App\Enums\EventStatus::Cancelled || $event->isCompleted()))
                <form method="POST" action="{{ route('dashboard.accounts.events.archive', [$account, $event]) }}">
                    @csrf
                    <x-ui.button type="submit" variant="secondary">
                        <x-ui.icon name="archive" class="h-4 w-4" />
                        {{ __('app.event_archive') }}
                    </x-ui.button>
                </form>
            @endif
            @if ($event->status === \App\Enums\EventStatus::Draft && $event->orders()->doesntExist())
                <form method="POST" action="{{ route('dashboard.accounts.events.destroy', [$account, $event]) }}">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger">
                        <x-ui.icon name="trash-2" class="h-4 w-4" />
                        {{ __('app.delete') }}
                    </x-ui.button>
                </form>
            @endif
        </section>
    @endif
</div>
@endsection
