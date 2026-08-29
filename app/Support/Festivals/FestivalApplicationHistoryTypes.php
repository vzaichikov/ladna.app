<?php

namespace App\Support\Festivals;

use Illuminate\Database\Eloquent\Builder;

class FestivalApplicationHistoryTypes
{
    public const Lifecycle = 'lifecycle';

    public const Reviews = 'reviews';

    public const Fields = 'fields';

    public const Payments = 'payments';

    public const ProgramResults = 'program_results';

    public const Other = 'other';

    /** @return array<int, string> */
    public function values(): array
    {
        return [
            self::Lifecycle,
            self::Reviews,
            self::Fields,
            self::Payments,
            self::ProgramResults,
            self::Other,
        ];
    }

    public function normalize(mixed $type): ?string
    {
        return is_string($type) && in_array($type, $this->values(), true) ? $type : null;
    }

    public function classify(string $action): string
    {
        if ($action === 'entry.reviewed'
            || ($this->startsWith($action, 'entry_step.') && $action !== 'entry_step.submitted')
            || $this->startsWith($action, 'requirement.')) {
            return self::Reviews;
        }

        if (in_array($action, ['entry.updated', 'entry.category_reassigned'], true)
            || $this->startsWithAny($action, ['response.', 'submission.'])) {
            return self::Fields;
        }

        if ($this->startsWithAny($action, ['charge.', 'payment.'])) {
            return self::Payments;
        }

        if ($this->startsWithAny($action, ['schedule.', 'timeline.', 'score_sheet.', 'penalty.', 'result.', 'battle.'])) {
            return self::ProgramResults;
        }

        if ($this->startsWithAny($action, ['entry.', 'entry_step.'])) {
            return self::Lifecycle;
        }

        return self::Other;
    }

    public function apply(Builder $query, string $type): void
    {
        match ($type) {
            self::Lifecycle => $query->where(function (Builder $query): void {
                $query->where('action', 'entry_step.submitted')
                    ->orWhere(function (Builder $query): void {
                        $query->where('action', 'like', 'entry.%')
                            ->whereNotIn('action', ['entry.reviewed', 'entry.updated', 'entry.category_reassigned']);
                    });
            }),
            self::Reviews => $query->where(function (Builder $query): void {
                $query->where('action', 'entry.reviewed')
                    ->orWhere(function (Builder $query): void {
                        $query->where('action', 'like', 'entry_step.%')
                            ->where('action', '!=', 'entry_step.submitted');
                    })
                    ->orWhere('action', 'like', 'requirement.%');
            }),
            self::Fields => $query->where(function (Builder $query): void {
                $query->whereIn('action', ['entry.updated', 'entry.category_reassigned'])
                    ->orWhere('action', 'like', 'response.%')
                    ->orWhere('action', 'like', 'submission.%');
            }),
            self::Payments => $this->whereStartsWithAny($query, ['charge.', 'payment.']),
            self::ProgramResults => $this->whereStartsWithAny($query, ['schedule.', 'timeline.', 'score_sheet.', 'penalty.', 'result.', 'battle.']),
            self::Other => $query->where(function (Builder $query): void {
                foreach (['entry.', 'entry_step.', 'requirement.', 'response.', 'submission.', 'charge.', 'payment.', 'schedule.', 'timeline.', 'score_sheet.', 'penalty.', 'result.', 'battle.'] as $prefix) {
                    $query->where('action', 'not like', $prefix.'%');
                }
            }),
            default => null,
        };
    }

    public function label(string $type): string
    {
        return match ($type) {
            self::Lifecycle => __('app.festival_history_type_lifecycle'),
            self::Reviews => __('app.festival_history_type_reviews'),
            self::Fields => __('app.festival_history_type_fields'),
            self::Payments => __('app.festival_history_type_payments'),
            self::ProgramResults => __('app.festival_history_type_program_results'),
            default => __('app.festival_history_type_other'),
        };
    }

    /** @param array<int, string> $prefixes */
    private function whereStartsWithAny(Builder $query, array $prefixes): void
    {
        $query->where(function (Builder $query) use ($prefixes): void {
            foreach ($prefixes as $index => $prefix) {
                if ($index === 0) {
                    $query->where('action', 'like', $prefix.'%');
                } else {
                    $query->orWhere('action', 'like', $prefix.'%');
                }
            }
        });
    }

    /** @param array<int, string> $prefixes */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($this->startsWith($value, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function startsWith(string $value, string $prefix): bool
    {
        return str_starts_with($value, $prefix);
    }
}
