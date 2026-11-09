<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\SaveFestivalHallLayout;
use App\Enums\FestivalAdmissionDeliveryMode;
use App\Http\Requests\FestivalHallLayoutRequest;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalSeatMapPng;
use App\Support\Festivals\FestivalSeatReservations;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\MoneyFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class FestivalHallLayoutController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly FestivalSeatReservations $reservations,
        private readonly SaveFestivalHallLayout $layout,
    ) {}

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $types = $festivalEdition->admissionTypes()
            ->where('is_assigned_seating', true)
            ->where('delivery_mode', FestivalAdmissionDeliveryMode::Venue)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (FestivalAdmissionType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'price' => MoneyFormatter::format($type->price_cents, $account->default_currency),
                'active' => $type->is_active,
            ]);

        return view('festivals.staff.hall-layout', [
            'account' => $account,
            'edition' => $festivalEdition,
            'workspacePermissions' => $permissions,
            'layout' => $this->present($festivalEdition),
            'types' => $types,
            'palette' => array_values(FestivalSeatMapPng::PALETTE),
            'maxSeats' => FestivalSeatMapPng::MAX_SEATS,
        ]);
    }

    public function update(FestivalHallLayoutRequest $request, Account $account, FestivalEdition $festivalEdition): JsonResponse
    {
        $this->layout->update($festivalEdition, $request->validated());

        return response()->json($this->present($festivalEdition->refresh()));
    }

    public function mode(Request $request, Account $account, FestivalEdition $festivalEdition): JsonResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $request->validate(['enabled' => ['required', 'boolean']]);
        $this->layout->mode($festivalEdition, $request->boolean('enabled'));

        return response()->json($this->present($festivalEdition->refresh()));
    }

    public function block(Request $request, Account $account, FestivalEdition $festivalEdition): JsonResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $data = $request->validate([
            'seat_ids' => ['required', 'array', 'list', 'max:'.FestivalSeatMapPng::MAX_SEATS],
            'seat_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'blocked' => ['required', 'boolean'],
        ]);

        $this->layout->block($festivalEdition, array_map('intval', $data['seat_ids']), $request->boolean('blocked'));

        return response()->json($this->present($festivalEdition->refresh()));
    }

    public function import(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalSeatMapPng $png): JsonResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $request->validate(['floor_image' => ['required', 'file', 'mimetypes:image/png', 'max:2048']]);
        abort_unless(extension_loaded('gd'), 503, __('app.festival_seating_admin.import_unavailable'));

        try {
            $result = $png->parse($request->file('floor_image')->get());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['floor_image' => __("app.festival_seating_admin.{$exception->getMessage()}")]);
        }

        $result['background'] = 'data:image/png;base64,'.base64_encode($result['background']);
        if (strlen($result['background']) > 350_000) {
            throw ValidationException::withMessages(['floor_image' => __('app.festival_seating_admin.background_too_large')]);
        }

        return response()->json($result);
    }

    public function template(Request $request, Account $account, FestivalEdition $festivalEdition, string $variant, FestivalSeatMapPng $png): Response
    {
        $this->financePermissions($request, $account, $festivalEdition);
        abort_unless(extension_loaded('gd'), 503, __('app.festival_seating_admin.import_unavailable'));

        return response($png->template(example: $variant === 'example'), headers: [
            'Content-Type' => 'image/png',
            'Content-Disposition' => ($request->boolean('preview') ? 'inline' : 'attachment').'; filename="ladna-seating-'.$variant.'.png"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string,mixed> */
    private function present(FestivalEdition $edition): array
    {
        $liveSeatIds = $this->reservations->live($edition)->pluck('id')->flip();
        $seats = $edition->seats()->orderBy('id')->get();

        return [
            'floors' => $edition->seating_floors ?? [],
            'seats' => $seats->map(fn ($seat): array => [
                ...$seat->only('id', 'floor_key', 'section', 'group_kind', 'group_label', 'seat_number', 'x', 'y', 'festival_admission_type_id', 'accessibility', 'sightline', 'is_blocked'),
                'unavailable' => $liveSeatIds->has($seat->id),
            ]),
            'has_history' => $edition->seats()->has('orderSeats')->exists(),
            'has_holds' => $this->reservations->activeHolds($edition)->exists(),
            'enabled' => $edition->assigned_seating_enabled,
        ];
    }

    /** @return array<string,bool> */
    private function financePermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        abort_unless($edition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['finance'], 403);

        return $permissions;
    }
}
