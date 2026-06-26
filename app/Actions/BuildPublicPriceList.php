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
            'sections' => $groupPlans
                ->groupBy(fn (ClassPassPlan $plan): string => $this->sectionKey($plan, $scheduleKind))
                ->map(fn (Collection $plans, string $key): array => [
                    'key' => $key,
                    'title' => $this->sectionTitle($plans->first(), $scheduleKind, $key),
                    'sort_key' => $this->sectionSortKey($plans->first(), $scheduleKind, $key),
                    'plans' => $plans->values(),
                ])
                ->sortBy('sort_key')
                ->map(fn (array $section): array => [
                    'key' => $section['key'],
                    'title' => $section['title'],
                    'plans' => $section['plans'],
                ])
                ->values(),
        ];
    }

    private function sectionKey(ClassPassPlan $plan, ScheduleKind $scheduleKind): string
    {
        if ($this->hasActiveSegment($plan)) {
            return 'segment:'.$plan->classPassSegment->slug;
        }

        return match ($scheduleKind) {
            ScheduleKind::GroupClass => $plan->available_until_time ? 'morning' : 'full_day',
            ScheduleKind::PrivateLesson => $plan->trainerTypes->pluck('name')->sort()->implode('|') ?: 'any_trainer',
            ScheduleKind::RoomRental => $plan->rooms->pluck('slug')->sort()->implode('|') ?: 'all_rooms',
        };
    }

    private function sectionTitle(?ClassPassPlan $plan, ScheduleKind $scheduleKind, string $key): string
    {
        if (! $plan) {
            return '';
        }

        if ($this->hasActiveSegment($plan)) {
            return $plan->classPassSegment->name;
        }

        return match ($scheduleKind) {
            ScheduleKind::GroupClass => $key === 'morning' ? __('app.morning_format') : __('app.full_day'),
            ScheduleKind::PrivateLesson => $plan->trainerTypes->pluck('name')->sort()->implode(', ') ?: __('app.any_trainer_type'),
            ScheduleKind::RoomRental => $plan->rooms->pluck('name')->sort()->implode(', ') ?: __('app.all_rooms'),
        };
    }

    private function sectionSortKey(?ClassPassPlan $plan, ScheduleKind $scheduleKind, string $key): string
    {
        if (! $plan) {
            return '99999-'.$key;
        }

        $segmentSort = $this->hasActiveSegment($plan)
            ? $plan->classPassSegment->sort_order
            : -1;

        return sprintf('%05d-%05d-%s-%s', $segmentSort, $plan->sort_order, $scheduleKind->value, $key);
    }

    private function hasActiveSegment(ClassPassPlan $plan): bool
    {
        return $plan->classPassSegment !== null && $plan->classPassSegment->is_active;
    }
}
