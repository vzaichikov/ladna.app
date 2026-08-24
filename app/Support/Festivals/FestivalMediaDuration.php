<?php

namespace App\Support\Festivals;

use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementType;
use App\Models\FestivalCategory;
use App\Models\FestivalRequirementDefinition;

class FestivalMediaDuration
{
    /** @return array{int|null, int|null} */
    public function bounds(FestivalRequirementDefinition $definition, FestivalCategory $category): array
    {
        if ($definition->input_type !== FestivalRequirementInputType::File) {
            return [null, null];
        }

        $minimumDuration = $definition->min_duration_seconds;
        $maximumDuration = $definition->max_duration_seconds;

        if ($this->inheritsCategoryBounds($definition)) {
            $minimumDuration ??= $category->min_duration_seconds;
            $maximumDuration ??= $category->max_duration_seconds;
        }

        return [$minimumDuration, $maximumDuration];
    }

    public function label(FestivalRequirementDefinition $definition, FestivalCategory $category): ?string
    {
        [$minimumDuration, $maximumDuration] = $this->bounds($definition, $category);

        if ($minimumDuration === null && $maximumDuration === null) {
            return null;
        }

        if ($minimumDuration !== null && $maximumDuration !== null) {
            return $minimumDuration === $maximumDuration
                ? __('app.festival_requirement_duration_label_exact', ['duration' => $this->format($minimumDuration)])
                : __('app.festival_requirement_duration_label_range', [
                    'min' => $this->format($minimumDuration),
                    'max' => $this->format($maximumDuration),
                ]);
        }

        return $minimumDuration !== null
            ? __('app.festival_requirement_duration_label_minimum', ['min' => $this->format($minimumDuration)])
            : __('app.festival_requirement_duration_label_maximum', ['max' => $this->format($maximumDuration)]);
    }

    public function invalidMessage(?int $minimumDuration, ?int $maximumDuration, int $actualDuration): string
    {
        $actual = $this->format($actualDuration);

        if ($minimumDuration !== null && $maximumDuration !== null) {
            return $minimumDuration === $maximumDuration
                ? __('app.festival_file_duration_invalid_exact', [
                    'duration' => $this->format($minimumDuration),
                    'actual' => $actual,
                ])
                : __('app.festival_file_duration_invalid_range', [
                    'min' => $this->format($minimumDuration),
                    'max' => $this->format($maximumDuration),
                    'actual' => $actual,
                ]);
        }

        return $minimumDuration !== null
            ? __('app.festival_file_duration_invalid_minimum', [
                'min' => $this->format($minimumDuration),
                'actual' => $actual,
            ])
            : __('app.festival_file_duration_invalid_maximum', [
                'max' => $this->format($maximumDuration),
                'actual' => $actual,
            ]);
    }

    private function inheritsCategoryBounds(FestivalRequirementDefinition $definition): bool
    {
        return in_array($definition->type, [
            FestivalRequirementType::Music,
            FestivalRequirementType::QualificationVideo,
            FestivalRequirementType::Backdrop,
        ], true);
    }

    private function format(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
