<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Location;
use App\Models\StudioCashEntry;
use App\Models\User;
use App\Support\ActorSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

class RecordStudioCashEntry
{
    public function __construct(private readonly ActorSnapshot $actorSnapshot) {}

    public function execute(
        Account $account,
        Location $location,
        string $direction,
        int $amountCents,
        CarbonInterface $occurredAt,
        ?User $user,
        string $reason,
    ): StudioCashEntry {
        if ($location->account_id !== $account->id) {
            abort(404);
        }

        validator(
            [
                'direction' => $direction,
                'amount_cents' => $amountCents,
            ],
            [
                'direction' => ['required', Rule::in([
                    StudioCashEntry::DirectionIn,
                    StudioCashEntry::DirectionOut,
                ])],
                'amount_cents' => ['required', 'integer', 'min:1'],
            ],
        )->validate();

        return StudioCashEntry::query()->create([
            'account_id' => $account->id,
            'location_id' => $location->id,
            'direction' => $direction,
            'amount_cents' => $amountCents,
            'currency' => $account->default_currency,
            'occurred_at' => $occurredAt,
            ...$this->actorSnapshot->capture($account, $user),
            'reason' => $reason,
        ]);
    }
}
