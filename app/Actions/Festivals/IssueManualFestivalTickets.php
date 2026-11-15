<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\FestivalEntryStatus;
use App\Enums\FestivalPortalRole;
use App\Enums\FestivalTicketOrderSource;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCashEntry;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTicketOrderItem;
use App\Models\User;
use App\Support\Festivals\FestivalSeatReservations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IssueManualFestivalTickets
{
    public function __construct(
        private readonly FestivalTicketIssuer $issuer,
        private readonly FestivalSeatReservations $seats,
        private readonly RecordFestivalCashEntry $cashEntries,
    ) {}

    /**
     * @param  array<int, array{holder_name: string, festival_participant_id?: int|null, festival_judge_assignment_id?: int|null, automation_key?: string|null}>  $ticketSpecifications
     * @param  list<int>  $seatIds
     * @param  array{amount_cents: int, idempotency_key: string}|null  $cashSale
     */
    public function execute(
        FestivalEdition $edition,
        FestivalPortalUser $guest,
        FestivalAdmissionType $admissionType,
        User $actor,
        array $ticketSpecifications,
        array $seatIds = [],
        ?array $cashSale = null,
    ): ?FestivalTicketOrder {
        abort_unless($edition->account_id === $guest->account_id && $edition->account_id === $admissionType->account_id, 404);
        abort_unless($actor->can('manageFestivalFinance', $edition->account), 403);

        $amountCents = $cashSale['amount_cents'] ?? 0;
        $isCashPresale = $cashSale !== null;
        if ($isCashPresale && ($amountCents <= 0 || count($ticketSpecifications) !== 1)) {
            throw new \InvalidArgumentException('A Festival cash presale requires a positive amount and one ticket.');
        }
        $orderReference = $isCashPresale
            ? 'FTO-'.Str::upper(substr(hash('sha256', $edition->id.':'.strtolower($cashSale['idempotency_key'])), 0, 32))
            : 'FTO-'.Str::upper(Str::random(18));

        return DB::transaction(function () use ($edition, $guest, $admissionType, $actor, $ticketSpecifications, $seatIds, $isCashPresale, $amountCents, $orderReference): ?FestivalTicketOrder {
            $edition = FestivalEdition::query()->where('account_id', $edition->account_id)->lockForUpdate()->findOrFail($edition->id);
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

            if ($isCashPresale) {
                $existing = $edition->ticketOrders()->with(['items', 'tickets'])->where('order_id', $orderReference)->first();
                if ($existing) {
                    $ticket = $existing->tickets->first();
                    if ($existing->source !== FestivalTicketOrderSource::CashPresale
                        || $existing->festival_portal_user_id !== $guest->id
                        || $existing->amount_cents !== $amountCents
                        || $ticket?->festival_admission_type_id !== $admissionType->id
                        || $ticket->festival_seat_id !== ($seatIds[0] ?? null)
                        || $ticket->holder_name !== $prepared[0]['holder_name']) {
                        throw ValidationException::withMessages(['cash_sale_key' => __('app.festival_cash_presale_request_conflict')]);
                    }

                    return $existing;
                }
            }

            $quantity = count($prepared);
            if ($admissionType->is_assigned_seating
                && ($quantity !== 1
                    || count($seatIds) > 1
                    || $prepared[0]['automation_key'] !== null
                    || $prepared[0]['festival_judge_assignment_id'] !== null
                    || $prepared[0]['festival_participant_id'] !== null)) {
                throw ValidationException::withMessages(['festival_admission_type_id' => __('app.festival_manual_ticket_type_invalid')]);
            }
            $selectedSeats = $this->seats->availableForStaff($edition, $seatIds, $admissionType);

            if ($purchase) {
                $heldQuantity = (int) FestivalTicketOrderItem::query()
                    ->whereHas('order', fn ($query) => $query
                        ->where('festival_edition_id', $edition->id)
                        ->countsAgainstInventory())
                    ->sum('quantity') + $this->seats->preholdCount($edition);
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
                'source' => $isCashPresale ? FestivalTicketOrderSource::CashPresale : FestivalTicketOrderSource::Manual,
                'issued_by_user_id' => $actor->id,
                'issued_at' => $issuedAt,
                'provider' => $isCashPresale ? FestivalTicketOrder::ProviderCashPresale : null,
                'order_id' => $orderReference,
                'status' => FestivalTicketOrderStatus::Paid,
                'buyer_name' => $guest->displayName(),
                'buyer_email' => FestivalPortalUser::normalizeEmail((string) $guest->email),
                'buyer_phone' => $guest->phone,
                'locale' => $guest->locale,
                'subtotal_cents' => $amountCents,
                'amount_cents' => $amountCents,
                'currency' => Str::upper($edition->account->default_currency),
                'access_token_encrypted' => $accessToken,
                'access_token_hash' => hash('sha256', $accessToken),
                'paid_at' => $issuedAt,
                'terms_accepted_at' => null,
                'terms_hash' => null,
            ]);
            $item = $order->items()->create([
                'account_id' => $edition->account_id,
                'festival_admission_type_id' => $admissionType->id,
                'admission_name' => $admissionType->name,
                'admission_description' => $admissionType->description,
                'price_tier' => 'manual',
                'unit_price_cents' => $amountCents,
                'quantity' => $quantity,
                'total_cents' => $amountCents,
                'subtotal_cents' => $amountCents,
                'final_total_cents' => $amountCents,
            ]);
            $this->seats->attach($item, $selectedSeats);

            $this->issuer->execute($order, $prepared);

            if ($isCashPresale) {
                $this->cashEntries->execute(
                    $order,
                    $actor,
                    FestivalCashEntry::DirectionIn,
                    FestivalCashEntry::PurposePresaleTicketSale,
                    __('app.festival_cash_presale_reason'),
                );
            }

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
