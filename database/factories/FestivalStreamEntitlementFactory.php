<?php

namespace Database\Factories;

use App\Models\FestivalAdmissionType;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPortalUser;
use App\Models\FestivalStreamEntitlement;
use App\Models\FestivalTicket;
use App\Models\FestivalTicketOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FestivalStreamEntitlement>
 */
class FestivalStreamEntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'festival_online_stream_id' => FestivalOnlineStream::factory()->enabled(),
            'festival_ticket_id' => function (array $attributes): int {
                $stream = FestivalOnlineStream::query()->findOrFail($attributes['festival_online_stream_id']);
                $guest = FestivalPortalUser::factory()->guest()->create(['account_id' => $stream->account_id]);
                $type = FestivalAdmissionType::factory()->create([
                    'account_id' => $stream->account_id,
                    'festival_edition_id' => $stream->festival_edition_id,
                    'delivery_mode' => 'online_stream',
                    'festival_online_stream_id' => $stream->id,
                    'max_per_order' => 1,
                ]);
                $order = FestivalTicketOrder::factory()->create([
                    'account_id' => $stream->account_id,
                    'festival_edition_id' => $stream->festival_edition_id,
                    'festival_portal_user_id' => $guest->id,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'expires_at' => null,
                ]);
                $item = $order->items()->create([
                    'account_id' => $stream->account_id,
                    'festival_admission_type_id' => $type->id,
                    'admission_name' => $type->name,
                    'unit_price_cents' => $type->price_cents,
                    'quantity' => 1,
                    'total_cents' => $type->price_cents,
                ]);
                $token = Str::random(64);

                return FestivalTicket::query()->create([
                    'account_id' => $stream->account_id,
                    'festival_edition_id' => $stream->festival_edition_id,
                    'festival_ticket_order_id' => $order->id,
                    'festival_ticket_order_item_id' => $item->id,
                    'festival_admission_type_id' => $type->id,
                    'code' => 'FST-'.Str::upper(Str::random(10)),
                    'token_encrypted' => $token,
                    'token_hash' => hash('sha256', $token),
                ])->id;
            },
            'account_id' => fn (array $attributes) => FestivalOnlineStream::query()->findOrFail($attributes['festival_online_stream_id'])->account_id,
            'festival_portal_user_id' => fn (array $attributes) => FestivalTicket::query()->findOrFail($attributes['festival_ticket_id'])->order->festival_portal_user_id,
        ];
    }
}
