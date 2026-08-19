<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PublicScheduleWeekRequest;
use App\Http\Resources\ScheduledClassResource;
use App\Models\Account;
use App\Models\Location;
use App\Models\ScheduledClass;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicScheduleController extends Controller
{
    public function schedule(string $accountSlug, string $locationSlug): AnonymousResourceCollection
    {
        return $this->classes($accountSlug, $locationSlug);
    }

    public function classes(string $accountSlug, string $locationSlug): AnonymousResourceCollection
    {
        [, $location] = $this->publicLocation($accountSlug, $locationSlug);

        $classes = $location->scheduledClasses()
            ->publicUpcoming()
            ->with(['account', 'location', 'room', 'classType.activityDirection', 'trainer'])
            ->limit(30)
            ->get();

        return ScheduledClassResource::collection($classes);
    }

    public function week(PublicScheduleWeekRequest $request, string $accountSlug, string $locationSlug): JsonResponse
    {
        [$account, $location] = $this->publicLocation($accountSlug, $locationSlug);
        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $date = $request->validated('date');
        $referenceDate = is_string($date)
            ? CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $weekStartsAt = $referenceDate->startOfWeek(CarbonInterface::MONDAY);
        $weekEndsAt = $referenceDate->endOfWeek(CarbonInterface::SUNDAY);

        $classes = $location->scheduledClasses()
            ->publicSchedule()
            ->with(['account', 'location', 'room', 'classType.activityDirection', 'trainer'])
            ->whereBetween('starts_at', [
                $weekStartsAt->setTimezone(config('app.timezone')),
                $weekEndsAt->setTimezone(config('app.timezone')),
            ])
            ->get();
        $classesByDate = $classes->groupBy(
            fn (ScheduledClass $scheduledClass): string => $scheduledClass->starts_at
                ->copy()
                ->timezone($timezone)
                ->toDateString(),
        );

        $days = collect(range(0, 6))
            ->map(function (int $offset) use ($classesByDate, $request, $weekStartsAt): array {
                $date = $weekStartsAt->addDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'iso_weekday' => $date->isoWeekday(),
                    'classes' => ScheduledClassResource::collection(
                        $classesByDate->get($date->toDateString(), collect()),
                    )->resolve($request),
                ];
            })
            ->all();

        return response()->json([
            'data' => $days,
            'meta' => [
                'timezone' => $timezone,
                'week_start' => $weekStartsAt->toDateString(),
                'week_end' => $weekEndsAt->toDateString(),
            ],
        ]);
    }

    /**
     * @return array{0: Account, 1: Location}
     */
    private function publicLocation(string $accountSlug, string $locationSlug): array
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return [$account, $location];
    }
}
