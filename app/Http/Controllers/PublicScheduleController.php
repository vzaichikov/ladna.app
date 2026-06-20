<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Location;
use App\Models\ScheduledClass;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class PublicScheduleController extends Controller
{
    public function show(Request $request, string $accountSlug, string $locationSlug): View
    {
        [$account, $location, $classes] = $this->scheduleFor($request, $accountSlug, $locationSlug);

        return view('public.schedule', [
            'account' => $account,
            'location' => $location,
            'classes' => $classes,
            'rooms' => $location->rooms()->active()->orderBy('name')->get(),
            'selectedRoomSlug' => $request->query('room'),
            'isEmbed' => false,
        ]);
    }

    public function embed(Request $request, string $accountSlug, string $locationSlug): View
    {
        [$account, $location, $classes] = $this->scheduleFor($request, $accountSlug, $locationSlug);

        return view('public.schedule', [
            'account' => $account,
            'location' => $location,
            'classes' => $classes,
            'rooms' => $location->rooms()->active()->orderBy('name')->get(),
            'selectedRoomSlug' => $request->query('room'),
            'isEmbed' => true,
        ]);
    }

    /**
     * @return array{0: Account, 1: Location, 2: Collection<int, ScheduledClass>}
     */
    private function scheduleFor(Request $request, string $accountSlug, string $locationSlug): array
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $classes = $location->scheduledClasses()
            ->publicUpcoming()
            ->when($request->query('room'), fn ($query, $roomSlug) => $query->whereHas('room', fn ($query) => $query->where('slug', $roomSlug)))
            ->with(['account', 'location', 'room', 'classType.activityDirection', 'trainer'])
            ->limit(30)
            ->get();

        return [$account, $location, $classes];
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }
}
