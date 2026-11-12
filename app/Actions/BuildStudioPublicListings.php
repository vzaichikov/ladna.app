<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Event;
use App\Models\FestivalEdition;
use Illuminate\Support\Collection;

class BuildStudioPublicListings
{
    /**
     * @return array{events: Collection<int, Event>, festivals: Collection<int, FestivalEdition>}
     */
    public function __invoke(Account $account): array
    {
        $events = $account->events()
            ->published()
            ->upcoming()
            ->select(['id', 'account_id', 'slug', 'title', 'summary', 'starts_at', 'ends_at', 'timezone'])
            ->with(['media' => fn ($query) => $query
                ->select(['id', 'event_id', 'image_path', 'alt_text', 'sort_order', 'is_cover'])
                ->where('is_cover', true)])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(6)
            ->get();
        $festivals = collect();

        if ($account->enable_festivals) {
            $festivals = $account->festivalEditions()
                ->published()->publiclyVisible()
                ->upcoming()
                ->where('show_on_studio_page', true)
                ->select(['id', 'account_id', 'festival_series_id', 'slug', 'title', 'summary', 'starts_at', 'ends_at', 'timezone'])
                ->with('coverMedia')
                ->orderBy('starts_at')
                ->orderBy('id')
                ->limit(6)
                ->get();
        }

        return compact('events', 'festivals');
    }
}
