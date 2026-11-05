<?php

namespace App\Http\Controllers;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionStatus;
use App\Http\Requests\FestivalSeatHoldRequest;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalSeat;
use App\Support\Festivals\FestivalSeatCheckout;
use App\Support\Festivals\FestivalSeatReservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FestivalSeatCheckoutController extends Controller
{
    public function __construct(
        private readonly FestivalSeatCheckout $checkout,
        private readonly FestivalSeatReservations $reservations,
    ) {}

    public function show(Request $request, string $accountSlug, string $editionSlug): JsonResponse
    {
        $edition = $this->edition($request, $accountSlug, $editionSlug);
        $hold = $this->holdData($request, $edition);
        $heldIds = $hold['seat_ids'] ?? [];
        $unavailable = $this->reservations->live($edition)->pluck('id')->flip();
        $types = $edition->admissionTypes()
            ->where('account_id', $edition->account_id)
            ->where('is_assigned_seating', true)
            ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue->value)
            ->get()
            ->keyBy('id');
        $saleOpen = $types->map(fn (FestivalAdmissionType $type): bool => $type->saleIsOpen());

        $seats = $edition->seats()->where('account_id', $edition->account_id)->whereIn('festival_admission_type_id', $types->keys())->orderBy('id')->get()
            ->map(fn (FestivalSeat $seat): array => [
                'id' => $seat->id,
                'floor' => $seat->floor_key,
                'section' => $seat->section,
                'group_kind' => $seat->group_kind, 'group_label' => $seat->group_label,
                'group' => $seat->group_kind === 'table'
                    ? __('app.festival_seating.table_label', ['label' => $seat->group_label])
                    : __('app.festival_seating.row_label', ['label' => $seat->group_label]),
                'number' => $seat->seat_number,
                'x' => $seat->x,
                'y' => $seat->y,
                'accessibility' => $seat->accessibility,
                'sightline' => $seat->sightline,
                'type_id' => $seat->festival_admission_type_id,
                'available' => ! $seat->is_blocked
                    && $saleOpen[$seat->festival_admission_type_id]
                    && (! isset($unavailable[$seat->id]) || in_array($seat->id, $heldIds, true)),
            ]);

        return response()->json([
            'floors' => $request->boolean('availability_only') ? null : ($edition->seating_floors ?? []),
            'types' => $types->values()->map(fn (FestivalAdmissionType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'price_cents' => $type->currentPrice()['price_cents'],
                'max_per_order' => $type->max_per_order,
            ]),
            'seats' => $seats,
            'hold' => $hold,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function hold(FestivalSeatHoldRequest $request, string $accountSlug, string $editionSlug): JsonResponse
    {
        $edition = $this->edition($request, $accountSlug, $editionSlug);
        $seatIds = array_map(intval(...), $request->validated('seat_ids'));
        $draft = $seatIds === [] ? $this->checkout->draft($request, $edition) : $this->checkout->begin($request, $edition);
        $key = $this->checkout->key($request, $edition, $draft);

        if ($key !== null) {
            $this->reservations->hold($edition, $key, $seatIds);
        }

        return response()->json(['hold' => $this->holdData($request, $edition)])
            ->header('Cache-Control', 'private, no-store');
    }

    private function edition(Request $request, string $accountSlug, string $editionSlug): FestivalEdition
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug, 404);

        return FestivalEdition::query()->whereBelongsTo($account)->published()->publiclyVisible()
            ->where('slug', $editionSlug)
            ->where('assigned_seating_enabled', true)
            ->whereIn('status', [FestivalEditionStatus::Published->value, FestivalEditionStatus::InProgress->value])
            ->whereNull('cancelled_at')
            ->where('ends_at', '>', now())
            ->firstOrFail();
    }

    /** @return array{checkout_id: string, seat_ids: list<int>, expires_at: string, remaining_seconds: int}|null */
    private function holdData(Request $request, FestivalEdition $edition): ?array
    {
        $draft = $this->checkout->draft($request, $edition);
        $key = $this->checkout->key($request, $edition, $draft);
        if ($key === null) {
            return null;
        }

        $held = $this->reservations->held($edition, $key);
        $deadline = $held->first()?->hold_expires_at;
        if ($deadline === null) {
            return null;
        }

        return [
            'checkout_id' => $draft,
            'seat_ids' => $held->modelKeys(),
            'expires_at' => $deadline->toIso8601String(),
            'remaining_seconds' => (int) max(0, now()->diffInSeconds($deadline, false)),
        ];
    }
}
