<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClassPassPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'schedule_kind' => $this->schedule_kind->value,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency' => $this->currency,
            'sessions_count' => $this->sessions_count,
            'validity_days' => $this->validity_days,
            'available_from_time' => $this->available_from_time,
            'available_until_time' => $this->available_until_time,
            'allows_any_time' => $this->allows_any_time,
            'any_time_addon_price_cents' => $this->any_time_addon_price_cents,
            'is_trial' => $this->is_trial,
            'class_types' => $this->classTypes->map(fn ($classType): array => [
                'id' => $classType->id,
                'name' => $classType->name,
                'slug' => $classType->slug,
                'schedule_kind' => $classType->schedule_kind->value,
            ])->values(),
            'trainer_types' => $this->trainerTypes->map(fn ($trainerType): array => [
                'id' => $trainerType->id,
                'name' => $trainerType->name,
            ])->values(),
            'rooms' => $this->rooms->map(fn ($room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'slug' => $room->slug,
            ])->values(),
        ];
    }
}
