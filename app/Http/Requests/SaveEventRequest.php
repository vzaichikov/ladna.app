<?php

namespace App\Http\Requests;

use App\Enums\EventVenueKind;
use App\Models\Account;
use App\Models\Event;
use App\Models\Location;
use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && (bool) $this->user()?->can('manageEvents', $account);
    }

    public function rules(): array
    {
        $account = $this->route('account');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'summary' => ['nullable', 'string', 'max:500'],
            'description_html' => ['nullable', 'string', 'max:100000'],
            'rules_html' => ['nullable', 'string', 'max:50000'],
            'venue_kind' => ['required', Rule::enum(EventVenueKind::class)],
            'location_id' => [
                Rule::requiredIf($this->input('venue_kind') === EventVenueKind::Studio->value),
                'nullable',
                'integer',
                Rule::exists((new Location)->getTable(), 'id')->where('account_id', $account?->id)->where('is_active', true),
            ],
            'room_ids' => [Rule::requiredIf($this->input('venue_kind') === EventVenueKind::Studio->value), 'array', 'min:1'],
            'room_ids.*' => [
                'integer',
                'distinct',
                Rule::exists((new Room)->getTable(), 'id')->where('account_id', $account?->id)->where('is_active', true),
            ],
            'external_venue_name' => [Rule::requiredIf($this->input('venue_kind') === EventVenueKind::External->value), 'nullable', 'string', 'max:255'],
            'external_address' => [Rule::requiredIf($this->input('venue_kind') === EventVenueKind::External->value), 'nullable', 'string', 'max:500'],
            'external_map_url' => ['nullable', 'url:http,https', 'max:2048'],
            'external_directions' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'timezone' => ['required', 'timezone:all'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'gallery_images' => ['nullable', 'array', 'max:12'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'video_urls' => ['nullable', 'array', 'max:6'],
            'video_urls.*' => ['nullable', 'url:http,https', 'max:2048', 'regex:/^https?:\\/\\/(?:www\\.)?(?:youtube\\.com|youtu\\.be|vimeo\\.com)\\//i'],
            'confirm_material_change' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('app.event_title'),
            'summary' => __('app.event_summary'),
            'description_html' => __('app.event_description'),
            'rules_html' => __('app.event_rules'),
            'venue_kind' => __('app.event_venue'),
            'location_id' => __('app.location'),
            'room_ids' => __('app.rooms'),
            'room_ids.*' => __('app.rooms'),
            'external_venue_name' => __('app.event_external_venue_name'),
            'external_address' => __('app.address'),
            'external_map_url' => __('app.event_map_url'),
            'external_directions' => __('app.event_directions'),
            'starts_at' => __('app.starts_at'),
            'ends_at' => __('app.ends_at'),
            'timezone' => __('app.timezone'),
            'capacity' => __('app.event_capacity'),
            'cover_image' => __('app.event_cover'),
            'gallery_images' => __('app.event_gallery'),
            'gallery_images.*' => __('app.event_gallery'),
            'video_urls' => __('app.event_video_urls'),
            'video_urls.*' => __('app.event_video_urls'),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $event = $this->route('event');

                if ($event instanceof Event
                    && filled($this->input('capacity'))
                    && (int) $this->input('capacity') < $event->soldOrHeldQuantity()) {
                    $validator->errors()->add('capacity', __('app.event_capacity_below_reserved'));
                }

                if ($this->input('venue_kind') !== EventVenueKind::Studio->value) {
                    return;
                }

                $account = $this->route('account');

                if (! $account instanceof Account) {
                    return;
                }

                if ($account->rooms()
                    ->whereKey($this->input('room_ids', []))
                    ->where('location_id', '!=', (int) $this->input('location_id'))
                    ->exists()) {
                    $validator->errors()->add('room_ids', __('app.event_rooms_location_mismatch'));
                }
            },
        ];
    }
}
