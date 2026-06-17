<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledClassResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $this->displayTimezone();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at->copy()->timezone($timezone)->toIso8601String(),
            'ends_at' => $this->ends_at->copy()->timezone($timezone)->toIso8601String(),
            'location' => [
                'id' => $this->location->id,
                'name' => $this->location->name,
                'slug' => $this->location->slug,
            ],
            'room' => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->name,
                'slug' => $this->room->slug,
            ] : null,
            'class_type' => $this->classType ? [
                'id' => $this->classType->id,
                'name' => $this->classType->name,
                'slug' => $this->classType->slug,
            ] : null,
            'activity_direction' => $this->classType?->activityDirection ? [
                'id' => $this->classType->activityDirection->id,
                'name' => $this->classType->activityDirection->name,
                'slug' => $this->classType->activityDirection->slug,
            ] : null,
            'schedule_kind' => $this->classType?->schedule_kind?->value,
            'trainer' => $this->trainer ? [
                'id' => $this->trainer->id,
                'name' => $this->trainer->name,
                'photo_url' => $this->trainer->photoUrl(),
            ] : null,
            'capacity' => $this->capacity,
            'available_spots' => null,
            'booking_cutoff_minutes' => $this->effectiveBookingCutoffMinutes(),
            'status' => $this->status->value,
        ];
    }
}
