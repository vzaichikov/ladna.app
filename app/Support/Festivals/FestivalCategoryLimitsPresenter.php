<?php

namespace App\Support\Festivals;

use App\Models\FestivalCategory;

class FestivalCategoryLimitsPresenter
{
    /** @return array{participants: string, age: string|null, duration: string|null} */
    public function present(FestivalCategory $category): array
    {
        return [
            'participants' => $this->participants($category->min_members, $category->max_members),
            'age' => $this->age($category->min_age, $category->max_age),
            'duration' => $this->duration($category->min_duration_seconds, $category->max_duration_seconds),
        ];
    }

    private function participants(int $minimum, int $maximum): string
    {
        if ($minimum === $maximum) {
            return trans_choice('app.festival_participants_count', $minimum, ['count' => $minimum]);
        }

        return __('app.festival_participants_range', ['min' => $minimum, 'max' => $maximum]);
    }

    private function age(?int $minimum, ?int $maximum): ?string
    {
        if ($minimum === null && $maximum === null) {
            return null;
        }

        if ($minimum !== null && $maximum !== null) {
            return $minimum === $maximum
                ? __('app.festival_age_exact', ['age' => $minimum])
                : __('app.festival_age_range', ['min' => $minimum, 'max' => $maximum]);
        }

        return $minimum !== null
            ? __('app.festival_age_minimum', ['min' => $minimum])
            : __('app.festival_age_maximum', ['max' => $maximum]);
    }

    private function duration(?int $minimum, ?int $maximum): ?string
    {
        if ($minimum === null && $maximum === null) {
            return null;
        }

        if ($minimum !== null && $maximum !== null) {
            return $minimum === $maximum
                ? __('app.festival_duration_exact', ['duration' => $this->formatDuration($minimum)])
                : __('app.festival_duration_range', [
                    'min' => $this->formatDuration($minimum),
                    'max' => $this->formatDuration($maximum),
                ]);
        }

        return $minimum !== null
            ? __('app.festival_duration_minimum', ['min' => $this->formatDuration($minimum)])
            : __('app.festival_duration_maximum', ['max' => $this->formatDuration($maximum)]);
    }

    private function formatDuration(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
