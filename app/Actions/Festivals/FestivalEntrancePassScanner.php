<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalEntrancePassStatus;
use App\Enums\FestivalTeamMemberType;
use App\Models\FestivalEdition;
use App\Models\FestivalEntrancePass;
use App\Models\FestivalParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class FestivalEntrancePassScanner
{
    public function __construct(private readonly FestivalEntrancePassEligibility $eligibility) {}

    /** @return array<string, mixed> */
    public function checkIn(FestivalEdition $edition, string $value, User $actor, string $source, ?string $ip, bool $confirmed = false): array
    {
        $pass = FestivalEntrancePass::query()
            ->where('account_id', $edition->account_id)
            ->where(fn ($query) => $query
                ->where('token_hash', hash('sha256', trim($value)))
                ->orWhere('code', strtoupper(trim($value))))
            ->first();

        if (! $pass) {
            return ['state' => 'invalid', 'message' => __('app.festival_scan_invalid')];
        }
        if ($pass->festival_edition_id !== $edition->id) {
            return ['state' => 'wrong_edition', 'message' => __('app.festival_scan_wrong_edition')];
        }

        return DB::transaction(function () use ($pass, $edition, $actor, $source, $ip, $confirmed): array {
            $query = FestivalEntrancePass::query()->with(['edition', 'participant.portalUser'])->whereKey($pass->id);
            if ($confirmed) {
                $query->lockForUpdate();
            }

            $pass = $query->firstOrFail();
            if ($confirmed) {
                FestivalParticipant::query()->whereKey($pass->festival_participant_id)->lockForUpdate()->firstOrFail();
            }
            if (! $this->canBeUsed($pass)) {
                return ['state' => 'void', 'message' => __('app.festival_scan_pass_invalid')];
            }

            if ($pass->is_checked_in || $pass->participant->hasCheckedInFestivalTicket($edition)) {
                return [
                    'state' => 'already_checked_in',
                    'message' => __('app.festival_scan_duplicate'),
                    'checked_in_at' => $pass->checked_in_at?->toIso8601String(),
                    'checked_in_at_label' => $pass->checked_in_at?->timezone($edition->timezone)->format('d.m.Y H:i'),
                    'ticket' => $this->summary($pass),
                ];
            }
            if (! $confirmed) {
                return ['state' => 'awaiting_confirmation', 'message' => __('app.festival_scan_ready'), 'ticket' => $this->summary($pass)];
            }

            $pass->forceFill(['is_checked_in' => true, 'checked_in_at' => now()])->save();
            $this->audit($pass, $actor, 'check_in', $source, $ip);

            return ['state' => 'checked_in', 'message' => __('app.festival_scan_success'), 'ticket' => $this->summary($pass)];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function checkOut(FestivalEdition $edition, FestivalEntrancePass $pass, User $actor, string $reason, ?string $ip): array
    {
        abort_unless($pass->account_id === $edition->account_id && $pass->festival_edition_id === $edition->id, 404);

        return DB::transaction(function () use ($pass, $actor, $reason, $ip): array {
            $pass = FestivalEntrancePass::query()->whereKey($pass->id)->lockForUpdate()->firstOrFail();
            if (! $pass->is_checked_in) {
                return ['state' => 'not_checked_in', 'message' => __('app.festival_scan_not_checked_in')];
            }

            $pass->forceFill(['is_checked_in' => false, 'checked_in_at' => null])->save();
            $this->audit($pass, $actor, 'check_out', 'monitor', $ip, $reason);

            return ['state' => 'checked_out', 'message' => __('app.festival_scan_checked_out')];
        }, 3);
    }

    public function canBeUsed(FestivalEntrancePass $pass): bool
    {
        $pass->loadMissing(['edition', 'participant']);

        return $pass->status === FestivalEntrancePassStatus::Valid
            && ! in_array($pass->edition->status, [FestivalEditionStatus::Cancelled, FestivalEditionStatus::Archived], true)
            && ! $pass->edition->ends_at?->isPast()
            && $this->eligibility->isEligible($pass->edition, $pass->participant);
    }

    private function audit(FestivalEntrancePass $pass, User $actor, string $action, string $source, ?string $ip, ?string $reason = null): void
    {
        $pass->scans()->create([
            'account_id' => $pass->account_id,
            'festival_edition_id' => $pass->festival_edition_id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'source' => $source,
            'request_ip' => $ip,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    /** @return array{code: string, type: string, customer: string, kind: string, kind_label: string} */
    private function summary(FestivalEntrancePass $pass): array
    {
        $isHelper = $pass->participant->member_type === FestivalTeamMemberType::Helper;
        $kind = $isHelper ? 'helper_pass' : 'participant_pass';
        $label = $isHelper ? __('app.festival_helper_pass') : __('app.festival_participant_pass');

        return [
            'code' => $pass->code,
            'type' => $label,
            'customer' => $pass->participant->displayName(),
            'kind' => $kind,
            'kind_label' => $label,
        ];
    }
}
