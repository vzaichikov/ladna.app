<?php

namespace App\Support\Festivals;

use App\Models\FestivalEdition;
use App\Models\FestivalTicketOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FestivalSeatCheckout
{
    public function draft(Request $request, FestivalEdition $edition): ?string
    {
        return Cache::get($this->cacheKey($request, $edition));
    }

    public function begin(Request $request, FestivalEdition $edition): string
    {
        $draft = $this->draft($request, $edition);
        if ($draft === null || FestivalTicketOrder::query()
            ->whereBelongsTo($edition, 'edition')
            ->where('seat_hold_key', $this->key($request, $edition, $draft))
            ->exists()) {
            $draft = Str::random(48);
        }

        Cache::put($this->cacheKey($request, $edition), $draft, now()->addMinutes(15));

        return $draft;
    }

    public function key(Request $request, FestivalEdition $edition, ?string $draft): ?string
    {
        if (! $draft) {
            return null;
        }

        return hash_hmac('sha256', $draft, $this->cacheKey($request, $edition));
    }

    private function cacheKey(Request $request, FestivalEdition $edition): string
    {
        $scope = hash_hmac('sha256', $edition->id.'|'.$request->session()->getId(), (string) config('app.key'));

        return 'festival-seat-draft:'.$scope;
    }
}
