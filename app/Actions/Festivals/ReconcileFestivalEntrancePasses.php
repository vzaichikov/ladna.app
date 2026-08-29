<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalEntrancePassStatus;
use App\Models\FestivalEdition;
use App\Models\FestivalEntrancePass;
use App\Models\FestivalParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileFestivalEntrancePasses
{
    public function __construct(
        private readonly FestivalEntrancePassEligibility $eligibility,
        private readonly FestivalNotificationOutbox $notifications,
    ) {}

    /** @return array{editions: int, created: int, reactivated: int, disabled: int} */
    public function execute(): array
    {
        $totals = ['editions' => 0, 'created' => 0, 'reactivated' => 0, 'disabled' => 0];

        FestivalEdition::query()
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('id')
            ->chunkById(50, function ($editions) use (&$totals): void {
                foreach ($editions as $edition) {
                    $result = $this->reconcileEdition($edition);
                    $totals['editions']++;
                    $totals['created'] += $result['created'];
                    $totals['reactivated'] += $result['reactivated'];
                    $totals['disabled'] += $result['disabled'];
                }
            });

        return $totals;
    }

    /** @return array{created: int, reactivated: int, disabled: int} */
    public function reconcileEdition(FestivalEdition $edition): array
    {
        if ($edition->ends_at?->isPast()) {
            return ['created' => 0, 'reactivated' => 0, 'disabled' => 0];
        }

        $eligibleParticipants = in_array($edition->status, [FestivalEditionStatus::Cancelled, FestivalEditionStatus::Archived], true)
            ? collect()
            : $this->eligibility->queryForEdition($edition)
                ->with('portalUser')
                ->get()
                ->keyBy('id');

        $result = DB::transaction(function () use ($edition, $eligibleParticipants): array {
            $passes = FestivalEntrancePass::query()
                ->where('festival_edition_id', $edition->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('festival_participant_id');
            $createdOrReactivated = collect();
            $created = 0;
            $reactivated = 0;
            $disabled = 0;

            foreach ($eligibleParticipants as $participant) {
                $pass = $passes->get($participant->id);

                if (! $pass) {
                    $pass = $this->createPass($edition, $participant);
                    $created++;
                    $createdOrReactivated->push($participant);

                    continue;
                }

                if ($pass->status === FestivalEntrancePassStatus::Disabled) {
                    $credentials = $this->credentials();
                    $pass->forceFill([
                        ...$credentials,
                        'status' => FestivalEntrancePassStatus::Valid,
                        'is_checked_in' => false,
                        'checked_in_at' => null,
                        'disabled_at' => null,
                        'disabled_reason' => null,
                        'credentials_rotated_at' => now(),
                    ])->save();
                    $reactivated++;
                    $createdOrReactivated->push($participant);
                }
            }

            foreach ($passes->reject(fn (FestivalEntrancePass $pass): bool => $eligibleParticipants->has($pass->festival_participant_id)) as $pass) {
                if ($pass->status !== FestivalEntrancePassStatus::Valid) {
                    continue;
                }

                $pass->forceFill([
                    'status' => FestivalEntrancePassStatus::Disabled,
                    'is_checked_in' => false,
                    'checked_in_at' => null,
                    'disabled_at' => now(),
                    'disabled_reason' => 'eligibility_lost',
                ])->save();
                $disabled++;
            }

            return compact('created', 'reactivated', 'disabled', 'createdOrReactivated');
        }, 3);

        $result['createdOrReactivated']
            ->groupBy('festival_portal_user_id')
            ->each(function ($participants) use ($edition): void {
                /** @var FestivalParticipant $participant */
                $participant = $participants->first();
                $portalUser = $participant->portalUser;

                if ($portalUser) {
                    $passVersion = FestivalEntrancePass::query()
                        ->where('festival_edition_id', $edition->id)
                        ->whereIn('festival_participant_id', $participants->pluck('id'))
                        ->orderBy('id')
                        ->get(['id', 'updated_at'])
                        ->map(fn (FestivalEntrancePass $pass): string => $pass->id.':'.$pass->updated_at?->getTimestamp())
                        ->join('|');
                    $this->notifications->queueForEntrancePasses(
                        $portalUser,
                        $edition,
                        $participants->count(),
                        'reconcile:'.hash('sha256', $passVersion),
                    );
                }
            });

        return [
            'created' => $result['created'],
            'reactivated' => $result['reactivated'],
            'disabled' => $result['disabled'],
        ];
    }

    private function createPass(FestivalEdition $edition, FestivalParticipant $participant): FestivalEntrancePass
    {
        return FestivalEntrancePass::query()->create([
            'account_id' => $edition->account_id,
            'festival_edition_id' => $edition->id,
            'festival_participant_id' => $participant->id,
            ...$this->credentials(),
        ]);
    }

    /** @return array{code: string, token_encrypted: string, token_hash: string} */
    private function credentials(): array
    {
        $token = Str::random(64);

        do {
            $code = 'FSP-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
        } while (FestivalEntrancePass::query()->where('code', $code)->exists());

        return [
            'code' => $code,
            'token_encrypted' => $token,
            'token_hash' => hash('sha256', $token),
        ];
    }
}
