<?php

namespace App\Support\Festivals;

use App\Models\FestivalRequirementDefinition;
use Carbon\CarbonInterface;

class FestivalRequirementDeadlineResolver
{
    public const RegistrationOpensAt = 'registration_opens_at';

    public const RegistrationClosesAt = 'registration_closes_at';

    public const FestivalStartsAt = 'starts_at';

    /** @var array<int, string> */
    public const References = [
        self::RegistrationOpensAt,
        self::RegistrationClosesAt,
        self::FestivalStartsAt,
    ];

    public function dueAt(FestivalRequirementDefinition $definition): ?CarbonInterface
    {
        return $this->resolve($definition, 'due_rule') ?? $definition->due_at;
    }

    public function editableUntil(FestivalRequirementDefinition $definition): ?CarbonInterface
    {
        return $this->resolve($definition, 'editable_until_rule');
    }

    public function allowsPostConfirmationEdits(FestivalRequirementDefinition $definition): bool
    {
        return (bool) data_get($definition->validation, 'allow_post_confirmation_edits', false);
    }

    /** @return array{reference: string, offset_days: int}|null */
    public function rule(FestivalRequirementDefinition $definition, string $key): ?array
    {
        $reference = data_get($definition->validation, $key.'.reference');
        $offsetDays = data_get($definition->validation, $key.'.offset_days');

        if (! is_string($reference) || ! in_array($reference, self::References, true) || ! is_numeric($offsetDays)) {
            return null;
        }

        return ['reference' => $reference, 'offset_days' => (int) $offsetDays];
    }

    private function resolve(FestivalRequirementDefinition $definition, string $key): ?CarbonInterface
    {
        $rule = $this->rule($definition, $key);
        if (! $rule) {
            return null;
        }

        $definition->loadMissing('edition');
        $reference = $definition->edition?->{$rule['reference']};

        return $reference?->copy()
            ->timezone($definition->edition->timezone)
            ->addDays($rule['offset_days'])
            ->utc();
    }
}
