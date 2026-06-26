<?php

namespace App\Actions;

use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Location;
use Illuminate\Support\Collection;

class BuildPublicPriceList
{
    /**
     * @return Collection<int, array{key: string, title: string, sections: Collection<int, array{key: string, title: string, plans: Collection<int, ClassPassPlan>}>}>
     */
    public function execute(Account $account, Location $location): Collection
    {
        $plans = $account->classPassPlans()
            ->active()
            ->where(function ($query) use ($location): void {
                $query->whereDoesntHave('rooms')
                    ->orWhereHas('rooms', fn ($query) => $query->where('location_id', $location->id));
            })
            ->with(['classPassSegment', 'classTypes', 'trainerTypes', 'rooms.location'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return collect([
            $this->group($plans, ScheduleKind::GroupClass, 'group_classes_price'),
            $this->group($plans, ScheduleKind::PrivateLesson, 'private_lessons_price'),
            $this->group($plans, ScheduleKind::RoomRental, 'room_rental_price'),
        ])
            ->filter(fn (array $group): bool => $account->hasScheduleKindEnabled($group['key']) && $group['sections']->isNotEmpty())
            ->values();
    }

    /**
     * @param  Collection<int, ClassPassPlan>  $plans
     * @return array{key: string, title: string, sections: Collection<int, array{key: string, title: string, plans: Collection<int, ClassPassPlan>}>}
     */
    private function group(Collection $plans, ScheduleKind $scheduleKind, string $titleKey): array
    {
        $groupPlans = $plans->filter(fn (ClassPassPlan $plan): bool => $plan->schedule_kind === $scheduleKind);

        return [
            'key' => $scheduleKind->value,
            'title' => __('app.'.$titleKey),
            'sections' => $this->sections($groupPlans, $scheduleKind),
        ];
    }

    /**
     * @param  Collection<int, ClassPassPlan>  $plans
     * @return Collection<int, array{key: string, title: string, plans: Collection<int, ClassPassPlan>}>
     */
    private function sections(Collection $plans, ScheduleKind $scheduleKind): Collection
    {
        $plans = $plans
            ->sortBy(fn (ClassPassPlan $plan): string => $this->planSortKey($plan))
            ->values();

        if ($plans->isEmpty()) {
            return collect();
        }

        $segmentedPlans = $plans->filter(fn (ClassPassPlan $plan): bool => $this->hasPublicSegment($plan, $scheduleKind));

        if ($segmentedPlans->isEmpty()) {
            return collect([$this->anonymousSection('all', $plans)]);
        }

        $sections = collect();
        $anonymousPlans = $plans
            ->reject(fn (ClassPassPlan $plan): bool => $this->hasPublicSegment($plan, $scheduleKind))
            ->values();

        if ($anonymousPlans->isNotEmpty()) {
            $sections->push($this->anonymousSection('without_segment', $anonymousPlans));
        }

        return $sections
            ->concat($segmentedPlans
                ->groupBy(fn (ClassPassPlan $plan): string => (string) $plan->classPassSegment->id)
                ->map(fn (Collection $plans): array => $this->segmentSection($plans))
                ->sortBy('sort_key')
                ->values())
            ->map(fn (array $section): array => [
                'key' => $section['key'],
                'title' => $section['title'],
                'plans' => $section['plans'],
            ])
            ->values();
    }

    /**
     * @param  Collection<int, ClassPassPlan>  $plans
     * @return array{key: string, title: string, plans: Collection<int, ClassPassPlan>}
     */
    private function anonymousSection(string $key, Collection $plans): array
    {
        return [
            'key' => $key,
            'title' => '',
            'plans' => $plans,
        ];
    }

    /**
     * @param  Collection<int, ClassPassPlan>  $plans
     * @return array{key: string, title: string, sort_key: string, plans: Collection<int, ClassPassPlan>}
     */
    private function segmentSection(Collection $plans): array
    {
        $segment = $plans->first()->classPassSegment;

        return [
            'key' => 'segment:'.$segment->slug,
            'title' => $segment->name,
            'sort_key' => sprintf('%05d-%s-%010d', $segment->sort_order, mb_strtolower($segment->name), $segment->id),
            'plans' => $plans
                ->sortBy(fn (ClassPassPlan $plan): string => $this->planSortKey($plan))
                ->values(),
        ];
    }

    private function hasPublicSegment(ClassPassPlan $plan, ScheduleKind $scheduleKind): bool
    {
        $segment = $plan->classPassSegment;

        return $segment !== null
            && $segment->account_id === $plan->account_id
            && $segment->schedule_kind === $scheduleKind
            && $segment->is_active;
    }

    private function planSortKey(ClassPassPlan $plan): string
    {
        return sprintf('%05d-%s-%010d', $plan->sort_order, mb_strtolower($plan->name), $plan->id);
    }
}
