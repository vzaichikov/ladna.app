<?php

namespace App\Jobs;

use App\Actions\Festivals\IssueManualFestivalTickets;
use App\Actions\Festivals\ResolveFestivalGuest;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPortalRole;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class IssueFestivalParticipantTickets implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $editionId,
        public readonly int $registrantId,
        public readonly int $admissionTypeId,
        public readonly int $actorId,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->editionId, $this->registrantId, $this->admissionTypeId]);
    }

    public function handle(ResolveFestivalGuest $resolveGuest, IssueManualFestivalTickets $issueTickets): void
    {
        $edition = FestivalEdition::query()->with('account')->find($this->editionId);
        $registrant = FestivalPortalUser::query()
            ->whereKey($this->registrantId)
            ->where('role', FestivalPortalRole::Registrant->value)
            ->where('is_active', true)
            ->first();
        $admissionType = FestivalAdmissionType::query()->find($this->admissionTypeId);
        $actor = User::query()->find($this->actorId);
        if (! $edition || ! $registrant || ! $admissionType || ! $actor || $registrant->account_id !== $edition->account_id) {
            return;
        }

        $participants = FestivalParticipant::query()
            ->where('account_id', $edition->account_id)
            ->where('festival_portal_user_id', $registrant->id)
            ->whereNull('archived_at')
            ->whereHas('entries', fn ($query) => $query
                ->where('festival_edition_id', $edition->id)
                ->where('status', FestivalEntryStatus::Accepted->value))
            ->orderBy('id')
            ->get();
        if ($participants->isEmpty()) {
            return;
        }

        $guest = $resolveGuest->execute(
            $edition->account,
            $registrant->email,
            (string) $registrant->first_name,
            (string) $registrant->last_name,
            $registrant->phone,
            $registrant->locale,
        );
        if (! $guest) {
            return;
        }

        $issueTickets->execute(
            $edition,
            $guest,
            $admissionType,
            $actor,
            $participants->map(fn (FestivalParticipant $participant): array => [
                'holder_name' => $participant->displayName(),
                'festival_participant_id' => $participant->id,
                'automation_key' => 'participant:'.$participant->id,
            ])->all(),
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Festival participant ticket issuance failed.', [
            'edition_id' => $this->editionId,
            'registrant_id' => $this->registrantId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
