<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MobileAccountResource extends JsonResource
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
            'default_language' => $this->default_language,
            'country_code' => $this->country_code,
            'currency' => $this->default_currency,
            'timezone' => $this->timezone,
            'brand_color' => $this->brand_color,
            'logo_url' => $this->logoUrl(),
            'slogan' => $this->studio_slogan,
            'locations' => $this->whenLoaded('locations', fn () => $this->locations->map(fn ($location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'slug' => $location->slug,
                'address' => $location->address,
                'timezone' => $location->timezone,
                'is_active' => $location->is_active,
            ])->values()),
        ];
    }
}
