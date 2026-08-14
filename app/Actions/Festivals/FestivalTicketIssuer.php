<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalPortalRole;
use App\Models\FestivalStreamEntitlement;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use Illuminate\Support\Str;

class FestivalTicketIssuer
{
    public function __construct(private readonly FestivalNotificationOutbox $notifications) {}

    /**
     * @param  array<int, array{holder_name?: string|null, festival_participant_id?: int|null, festival_judge_assignment_id?: int|null, automation_key?: string|null}>  $ticketSpecifications
     */
    public function execute(FestivalTicketOrder $order, array $ticketSpecifications = []): void
    {
        $order->loadMissing(['account', 'edition', 'portalUser', 'items.admissionType.onlineStream']);
        $specificationPosition = 0;

        foreach ($order->items as $item) {
            $existing = $order->tickets()->where('festival_ticket_order_item_id', $item->id)->count();
            $specificationPosition += $existing;
            for ($position = $existing; $position < $item->quantity; $position++) {
                $token = Str::random(64);
                $specification = $ticketSpecifications[$specificationPosition] ?? [];
                $ticket = FestivalTicket::query()->create([
                    'account_id' => $order->account_id,
                    'festival_edition_id' => $order->festival_edition_id,
                    'festival_ticket_order_id' => $order->id,
                    'festival_ticket_order_item_id' => $item->id,
                    'festival_admission_type_id' => $item->festival_admission_type_id,
                    'holder_name' => $specification['holder_name'] ?? null,
                    'festival_participant_id' => $specification['festival_participant_id'] ?? null,
                    'festival_judge_assignment_id' => $specification['festival_judge_assignment_id'] ?? null,
                    'automation_key' => $specification['automation_key'] ?? null,
                    'code' => $this->uniqueCode(),
                    'token_encrypted' => $token,
                    'token_hash' => hash('sha256', $token),
                ]);

                $admissionType = $item->admissionType;
                if ($admissionType->delivery_mode === FestivalAdmissionDeliveryMode::OnlineStream) {
                    $stream = $admissionType->onlineStream;
                    if (! $stream?->is_enabled || ! $order->portalUser || $order->portalUser->role !== FestivalPortalRole::Guest) {
                        throw new \LogicException('Online Festival ticket cannot be issued without an enabled stream and Guest owner.');
                    }
                    FestivalStreamEntitlement::query()->create([
                        'account_id' => $order->account_id,
                        'festival_online_stream_id' => $stream->id,
                        'festival_ticket_id' => $ticket->id,
                        'festival_portal_user_id' => $order->portalUser->id,
                    ]);
                }

                $specificationPosition++;
            }
        }

        if ($ticketSpecifications !== [] && $specificationPosition !== count($ticketSpecifications)) {
            throw new \LogicException('Festival ticket specification count must match the number of issued tickets.');
        }

        $this->notifications->queueForTicketOrder($order, [
            'tickets_count' => $order->tickets()->count(),
        ]);
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'FST-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
        } while (FestivalTicket::query()->where('code', $code)->exists());

        return $code;
    }
}
