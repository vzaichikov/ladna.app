<?php

namespace App\Http\Controllers;

use App\Http\Requests\ToggleTrainerPrivateTimeframeRequest;
use App\Models\Account;
use App\Models\Location;
use App\Models\Trainer;
use App\Support\TrainerPrivateLessonAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class TrainerPrivateTimeframeController extends Controller
{
    public function mine(Request $request, Account $account, TrainerPrivateLessonAvailability $availability): View
    {
        abort_unless($account->trainerPrivateTimeframesEnabled(), 404);

        $trainer = $account->trainers()
            ->whereBelongsTo($request->user(), 'user')
            ->firstOrFail();

        return $this->showTimeline($request, $account, $trainer, $availability, false);
    }

    public function edit(Request $request, Account $account, Trainer $trainer, TrainerPrivateLessonAvailability $availability): View
    {
        $this->ensureBelongsToAccount($account, $trainer);
        $this->authorize('manageTrainers', $account);
        abort_unless($account->trainerPrivateTimeframesEnabled(), 404);

        return $this->showTimeline($request, $account, $trainer, $availability, true);
    }

    public function toggle(ToggleTrainerPrivateTimeframeRequest $request, Account $account, Trainer $trainer, TrainerPrivateLessonAvailability $availability): JsonResponse
    {
        $this->ensureBelongsToAccount($account, $trainer);
        abort_unless($account->trainerPrivateTimeframesEnabled(), 404);

        $validated = $request->validated();
        $location = $account->locations()
            ->active()
            ->whereKey((int) $validated['location_id'])
            ->firstOrFail();
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d\TH:i', (string) $validated['starts_at'], $timezone);

        if (! in_array($startsAt->minute, [0, 30], true)) {
            return response()->json([
                'message' => __('app.trainer_private_timeframe_invalid_step'),
            ], 422);
        }

        $selected = (bool) $validated['selected'];

        if ($selected && ! $availability->cellCanBeSelected($account, $trainer, $location, $startsAt)) {
            return response()->json([
                'message' => __('app.trainer_private_timeframe_unavailable'),
            ], 422);
        }

        $isSelected = $availability->toggleCell($account, $trainer, $location, $startsAt, $selected);

        return response()->json([
            'selected' => $isSelected,
            'message' => $isSelected
                ? __('app.trainer_private_timeframe_selected')
                : __('app.trainer_private_timeframe_removed'),
        ]);
    }

    private function showTimeline(
        Request $request,
        Account $account,
        Trainer $trainer,
        TrainerPrivateLessonAvailability $availability,
        bool $adminMode,
    ): View {
        $locations = $availability->locationsForTrainer($account, $trainer);
        abort_if($locations->isEmpty(), 404);

        $location = $this->selectedLocation($request, $locations->first(), $locations);
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $weekStart = $this->selectedWeekStart($request, $today, $account);
        $lastAllowed = $today->addWeeks($account->trainerPrivateTimeframeWeeks())->endOfDay();
        $previousWeekStart = $weekStart->subWeek()->lessThan($today) ? null : $weekStart->subWeek();
        $nextWeekStart = $weekStart->addWeek()->greaterThan($lastAllowed) ? null : $weekStart->addWeek();

        return view('trainer-private-timeframes.index', [
            'account' => $account,
            'trainer' => $trainer,
            'locations' => $locations,
            'selectedLocation' => $location,
            'timelineDays' => $availability->timeline($account, $trainer, $location, $weekStart),
            'weekStart' => $weekStart,
            'weekEnd' => $weekStart->addDays(6),
            'previousWeekStart' => $previousWeekStart,
            'nextWeekStart' => $nextWeekStart,
            'adminMode' => $adminMode,
        ]);
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function selectedLocation(Request $request, Location $fallback, Collection $locations): Location
    {
        $locationId = (int) $request->query('location_id');

        return $locations->first(fn (Location $location): bool => $location->id === $locationId) ?? $fallback;
    }

    private function selectedWeekStart(Request $request, CarbonImmutable $today, Account $account): CarbonImmutable
    {
        $weekStart = $today;
        $requestedWeek = (string) $request->query('week', '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedWeek) === 1) {
            try {
                $weekStart = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $requestedWeek.' 00:00:00', $today->timezoneName);
            } catch (\Throwable) {
                $weekStart = $today;
            }
        }

        if ($weekStart->lessThan($today)) {
            return $today;
        }

        $lastAllowed = $today->addWeeks($account->trainerPrivateTimeframeWeeks())->endOfDay();

        if ($weekStart->greaterThan($lastAllowed)) {
            return $today;
        }

        return $weekStart->startOfDay();
    }

    private function ensureBelongsToAccount(Account $account, Trainer $trainer): void
    {
        abort_unless($trainer->account_id === $account->id, 404);
    }
}
