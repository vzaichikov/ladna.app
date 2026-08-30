<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Festivals\FestivalBattleMatchSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FestivalBattleMatchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(FestivalBattleMatchSnapshot::class)->for($this->resource);
    }
}
