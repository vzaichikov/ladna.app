<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueManualFestivalTickets
{
    public function __construct(private readonly FestivalTicketIssuer $issuer) {}

    /**
     * @param  array<int, array{holder_name: string, festival_participant_id?: int|null, festival_judge_assignment_id?: int|null, automation_key?: string|null}>  $ticketSpecifications
     */
    public function execute(
        FestivalEdition $edition,
        FestivalPortalUser $guest,
        FestivalAdmissionType $admissionType,
        User $actor,
        array $ticketSpecifications,
    ): ?FestivalTicketOrder {
        abort_unless($edition->account_id === $guest->account_id && $edition->account_id === $admissionType->account_id, 404);
        abort_unless($actor->can('manageFestivalFinance', $edition->account), 403);

        return DB::transaction(function () use ($edition, $guest, $admissionType, $actor, $ticketSpecifications): ?FestivalTicketOrder {
            $guest = FestivalPortalUser::query()
                ->whereKey($guest->id)
                ->where('account_id', $edition->account_id)
                ->where('role', FestivalPortalRole::Guest->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $guest) {
                throw ValidationException::withMessages(['festival_portal_user_id' => __('app.festival_manual_ticket_guest_invalid')]);
            }

            $purchase = FestivalEditionPurchase::query()
                ->with('package')
                ->where('festival_edition_id', $edition->id)
                ->lockForUpdate()
                ->first();
            abort_if($purchase?->status === FestivalEditionPurchaseStatus::PaymentReversed, 423, __('app.festival_payment_reversed_readonly'));

            $admissionType = FestivalAdmissionType::query()
                ->whereKey($admissionType->id)
                ->where('account_id', $edition->account_id)
                ->where('festival_edition_id', $edition->id)
                ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $admissionType) {
                throw ValidationException::withMessages(['festival_admission_type_id' => __('app.festival_manual_ticket_type_invalid')]);
            }

            $prepared = $this->prepareSpecifications($edition, $ticketSpecifications);
            if ($prepared === []) {
                return null;
            }

            $quantity = count($prepared);
            if ($purchase) {
                $heldQuantity = (int) FestivalTicketOrderItem::query()
                    ->whereHas('order', fn ($query) => $query
                        ->where('festival_edition_id', $edition->id)
                        ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                        ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
                    ->sum('quantity');
                if ($heldQuantity + $quantity > $purchase->package->max_tickets) {
                    throw ValidationException::withMessages(['festival_admission_type_id' => __('app.festival_ticket_limit_exceeded', ['limit' => $purchase->package->max_tickets])]);
                }
            }

            if ($admissionType->soldOrHeldQuantity() + $quantity > $admissionType->inventory) {
                throw ValidationException::withMessages(['festival_admission_type_id' => __('app.festival_admission_sold_out')]);
            }

            $accessToken = Str::random(64);
            $issuedAt = now();
            $order = FestivalTicketOrder::query()->create([
                'account_id' => $edition->account_id,
                'festival_edition_id' => $edition->id,
                'festival_portal_user_id' => $guest->id,
                'source' => FestivalTicketOrderSource::Manual,
                'issued_by_user_id' => $actor->id,
                'issued_at' => $issuedAt,
                'provider' => null,
                'order_id' => 'FTO-'.Str::upper(Str::random(18)),
                'status' => FestivalTicketOrderStatus::Paid,
                'buyer_name' => $guest->displayName(),
                'buyer_email' => FestivalPortalUser::normalizeEmail((string) $guest->email),
                'buyer_phone' => $guest->phone,
                'locale' => $guest->locale,
                'amount_cents' => 0,
                'currency' => Str::upper($edition->account->default_currency),
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'paid_at' => $issuedAt,
                'terms_accepted_at' => null,
                'terms_hash' => null,
            ]);
            $order->items()->create([
                'account_id' => $edition->account_id,
                'festival_admission_type_id' => $admissionType->id,
                'admission_name' => $admissionType->name,
                'admission_description' => $admissionType->description,
                'price_tier' => 'manual',
                'unit_price_cents' => 0,
                'quantity' => $quantity,
                'total_cents' => 0,
            ]);

            $this->issuer->execute($order, $prepared);

            return $order->load(['items', 'tickets']);
        }, 3);
    }

    /**
     * @param  array<int, array{holder_name: string, festival_participant_id?: int|null, festival_judge_assignment_id?: int|null, automation_key?: string|null}>  $ticketSpecifications
     * @return array<int, array{holder_name: string, festival_participant_id: int|null, festival_judge_assignment_id: int|null, automation_key: string|null}>
     */
    private function prepareSpecifications(FestivalEdition $edition, array $ticketSpecifications): array
    {
        if ($ticketSpecifications === []) {
            throw ValidationException::withMessages(['holder_name' => __('app.festival_manual_ticket_holder_required')]);
        }

        $automationKeys = collect($ticketSpecifications)->pluck('automation_key')->filter()->unique()->values();
        $alreadyIssued = $automationKeys->isEmpty()
            ? collect()
            : FestivalTicket::query()
                ->where('festival_edition_id', $edition->id)
                ->whereIn('automation_key', $automationKeys)
                ->lockForUpdate()
                ->pluck('automation_key');

        $prepared = [];
        $seenAutomationKeys = [];
        foreach ($ticketSpecifications as $specification) {
            $holderName = trim($specification['holder_name']);
            if ($holderName === '' || mb_strlen($holderName) > 255) {
                throw ValidationException::withMessages(['holder_name' => __('app.festival_manual_ticket_holder_required')]);
            }

            $participantId = isset($specification['festival_participant_id']) ? (int) $specification['festival_participant_id'] : null;
            $judgeAssignmentId = isset($specification['festival_judge_assignment_id']) ? (int) $specification['festival_judge_assignment_id'] : null;
            if ($participantId && $judgeAssignmentId) {
                throw new \LogicException('A Festival ticket can have only one automation provenance source.');
            }

            $automationKey = filled($specification['automation_key'] ?? null) ? (string) $specification['automation_key'] : null;
            if ($automationKey !== null && isset($seenAutomationKeys[$automationKey])) {
                continue;
            }
            if ($automationKey !== null && $alreadyIssued->contains($automationKey)) {
                continue;
            }
            if ($automationKey !== null) {
                $seenAutomationKeys[$automationKey] = true;
            }

            if ($participantId) {
                $participantExists = FestivalParticipant::query()
                    ->whereKey($participantId)
                    ->where('account_id', $edition->account_id)
                    ->whereNull('archived_at')
                    ->whereHas('entries', fn ($query) => $query
                        ->where('festival_edition_id', $edition->id)
                        ->where('status', FestivalEntryStatus::Accepted->value))
                    ->lockForUpdate()
                    ->exists();
                if (! $participantExists) {
                    continue;
                }
            }

            if ($judgeAssignmentId) {
                $assignmentExists = FestivalJudgeAssignment::query()
                    ->whereKey($judgeAssignmentId)
                    ->where('account_id', $edition->account_id)
                    ->where('festival_edition_id', $edition->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->exists();
                if (! $assignmentExists) {
                    continue;
                }
            }

            $prepared[] = [
                'holder_name' => $holderName,
                'festival_participant_id' => $participantId,
                'festival_judge_assignment_id' => $judgeAssignmentId,
                'automation_key' => $automationKey,
            ];
        }

        return $prepared;
    }
}
