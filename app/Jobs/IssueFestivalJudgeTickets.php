<?php

namespace App\Jobs;

use App\Actions\Festivals\IssueManualFestivalTickets;
use App\Actions\Festivals\ResolveFestivalGuest;
use App\Enums\FestivalPortalRole;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class IssueFestivalJudgeTickets implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<int, int>  $assignmentIds
     */
    public function __construct(
        public readonly int $editionId,
        public readonly string $normalizedEmail,
        public readonly array $assignmentIds,
        public readonly int $admissionTypeId,
        public readonly int $actorId,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->editionId, hash('sha256', $this->normalizedEmail), $this->admissionTypeId]);
    }

    public function handle(ResolveFestivalGuest $resolveGuest, IssueManualFestivalTickets $issueTickets): void
    {
        $edition = FestivalEdition::query()->with('account')->find($this->editionId);
        $admissionType = FestivalAdmissionType::query()->find($this->admissionTypeId);
        $actor = User::query()->find($this->actorId);
        if (! $edition || ! $admissionType || ! $actor) {
            return;
        }

        $assignments = FestivalJudgeAssignment::query()
            ->where('account_id', $edition->account_id)
            ->where('festival_edition_id', $edition->id)
            ->where('is_active', true)
            ->whereKey($this->assignmentIds)
            ->with(['portalUser', 'user'])
            ->orderBy('id')
            ->get();
        $assignment = $assignments->first(function (FestivalJudgeAssignment $candidate): bool {
            $email = $candidate->portalUser?->role === FestivalPortalRole::Judge && $candidate->portalUser->is_active
                ? $candidate->portalUser->email
                : $candidate->user?->email;

            return FestivalPortalUser::normalizeEmail((string) $email) === $this->normalizedEmail;
        });
        if (! $assignment) {
            return;
        }

        $source = $assignment->portalUser?->role === FestivalPortalRole::Judge && $assignment->portalUser->is_active
            ? $assignment->portalUser
            : $assignment->user;
        if (! $source) {
            return;
        }

        [$firstName, $lastName] = $source instanceof FestivalPortalUser
            ? [(string) $source->first_name, (string) $source->last_name]
            : $this->splitName((string) $source->name);
        $guest = $resolveGuest->execute(
            $edition->account,
            $source->email,
            $firstName,
            $lastName,
            $source->phone,
            $source instanceof FestivalPortalUser ? $source->locale : $edition->account->default_language,
        );
        if (! $guest) {
            return;
        }

        $issueTickets->execute($edition, $guest, $admissionType, $actor, [[
            'holder_name' => $assignment->display_name,
            'festival_judge_assignment_id' => $assignment->id,
            'automation_key' => 'judge:'.hash('sha256', $this->normalizedEmail),
        ]]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Festival judge ticket issuance failed.', [
            'edition_id' => $this->editionId,
            'recipient' => hash('sha256', $this->normalizedEmail),
            'error' => $exception?->getMessage(),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
