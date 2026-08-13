<?php

namespace App\Http\Controllers;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalTicketOrderStatus;
use App\Http\Requests\FestivalAdmissionTypeRequest;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\Payments\PaymentAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalAdmissionTypeController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $festivalEdition->loadMissing('onlineStream');

        return view('festivals.staff.admission-type-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'admissionType' => new FestivalAdmissionType(['is_active' => true, 'max_per_order' => 10]),
            'isLocked' => false,
            'workspacePermissions' => $permissions,
            'onlineStream' => $festivalEdition->onlineStream,
        ]);
    }

    public function store(FestivalAdmissionTypeRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $this->admissionTypeData($request->validated(), $festivalEdition);

        DB::transaction(function () use ($account, $festivalEdition, $data): void {
            $purchase = $this->lockedPurchase($festivalEdition);
            $this->assertPackageInventory($festivalEdition, $purchase, (int) $data['inventory']);
            $festivalEdition->admissionTypes()->create([
                'account_id' => $account->id,
                ...$data,
                'sort_order' => ((int) $festivalEdition->admissionTypes()->max('sort_order')) + 1,
            ]);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalAdmissionType $festivalAdmissionType): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $this->assertAdmissionType($account, $festivalEdition, $festivalAdmissionType);
        $festivalEdition->loadMissing('onlineStream');

        return view('festivals.staff.admission-type-form', [
            'account' => $account,
            'edition' => $festivalEdition,
            'admissionType' => $festivalAdmissionType,
            'isLocked' => $festivalAdmissionType->hasLockedPurchaseHistory(),
            'workspacePermissions' => $permissions,
            'onlineStream' => $festivalEdition->onlineStream,
        ]);
    }

    public function update(FestivalAdmissionTypeRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalAdmissionType $festivalAdmissionType): RedirectResponse
    {
        $this->assertAdmissionType($account, $festivalEdition, $festivalAdmissionType);
        $data = $this->admissionTypeData($request->validated(), $festivalEdition);

        DB::transaction(function () use ($account, $festivalEdition, $festivalAdmissionType, $data): void {
            $purchase = $this->lockedPurchase($festivalEdition);
            $admissionType = FestivalAdmissionType::query()->whereKey($festivalAdmissionType->id)->lockForUpdate()->firstOrFail();
            $this->assertAdmissionType($account, $festivalEdition, $admissionType);

            if ($admissionType->hasLockedPurchaseHistory()) {
                throw ValidationException::withMessages(['admission_type' => __('app.festival_admission_type_locked')]);
            }

            $activeQuantity = $admissionType->soldOrHeldQuantity();
            if ((int) $data['inventory'] < $activeQuantity) {
                throw ValidationException::withMessages(['inventory' => __('app.festival_admission_inventory_below_reserved', ['count' => $activeQuantity])]);
            }

            $earlyQuantity = $this->earlySoldOrHeldQuantity($admissionType);
            if (($data['early_bird_quota'] ?? null) !== null && (int) $data['early_bird_quota'] < $earlyQuantity) {
                throw ValidationException::withMessages(['early_bird_quota' => __('app.festival_admission_early_quota_below_reserved', ['count' => $earlyQuantity])]);
            }

            $this->assertPackageInventory($festivalEdition, $purchase, (int) $data['inventory'], $admissionType);
            $admissionType->update($data);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalAdmissionType $festivalAdmissionType): RedirectResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $this->assertAdmissionType($account, $festivalEdition, $festivalAdmissionType);

        DB::transaction(function () use ($account, $festivalEdition, $festivalAdmissionType): void {
            $this->lockedPurchase($festivalEdition);
            $admissionType = FestivalAdmissionType::query()->whereKey($festivalAdmissionType->id)->lockForUpdate()->firstOrFail();
            $this->assertAdmissionType($account, $festivalEdition, $admissionType);

            if ($admissionType->hasPurchaseHistory()) {
                throw ValidationException::withMessages(['admission_type' => __('app.festival_admission_type_delete_history_block')]);
            }

            $admissionType->delete();
        }, 3);

        return $this->redirect($account, $festivalEdition, __('app.festival_admission_deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function admissionTypeData(array $data, FestivalEdition $edition): array
    {
        $deliveryMode = FestivalAdmissionDeliveryMode::from($data['delivery_mode']);
        $stream = $edition->onlineStream()->first();
        if ($deliveryMode === FestivalAdmissionDeliveryMode::OnlineStream && ! $stream?->is_enabled) {
            throw ValidationException::withMessages(['delivery_mode' => __('app.festival_online_ticket_requires_stream')]);
        }
        $data['festival_online_stream_id'] = $deliveryMode === FestivalAdmissionDeliveryMode::OnlineStream ? $stream->id : null;
        if ($deliveryMode === FestivalAdmissionDeliveryMode::OnlineStream) {
            $data['max_per_order'] = 1;
        }
        $data['price_cents'] = (int) PaymentAmounts::decimalToCents($data['price']);
        $data['early_bird_price_cents'] = filled($data['early_bird_price'] ?? null)
            ? (int) PaymentAmounts::decimalToCents($data['early_bird_price'])
            : null;
        unset($data['price'], $data['early_bird_price']);

        foreach (['early_bird_ends_at', 'sales_starts_at', 'sales_ends_at'] as $field) {
            $data[$field] = filled($data[$field] ?? null)
                ? CarbonImmutable::parse((string) $data[$field], $edition->timezone)->utc()
                : null;
        }

        return $data;
    }

    private function lockedPurchase(FestivalEdition $edition): ?FestivalEditionPurchase
    {
        return FestivalEditionPurchase::query()
            ->with('package')
            ->where('festival_edition_id', $edition->id)
            ->lockForUpdate()
            ->first();
    }

    private function assertPackageInventory(FestivalEdition $edition, ?FestivalEditionPurchase $purchase, int $inventory, ?FestivalAdmissionType $except = null): void
    {
        if (! $purchase) {
            return;
        }

        $total = (int) $edition->admissionTypes()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->sum('inventory');

        if ($total + $inventory > $purchase->package->max_tickets) {
            throw ValidationException::withMessages(['inventory' => __('app.festival_ticket_inventory_limit_exceeded', ['limit' => $purchase->package->max_tickets])]);
        }
    }

    private function earlySoldOrHeldQuantity(FestivalAdmissionType $admissionType): int
    {
        return (int) $admissionType->orderItems()
            ->where('price_tier', 'early_bird')
            ->whereHas('order', fn ($query) => $query
                ->whereIn('status', [FestivalTicketOrderStatus::Pending->value, FestivalTicketOrderStatus::Paid->value, FestivalTicketOrderStatus::PaidRequiresRefund->value])
                ->where(fn ($query) => $query->where('status', '!=', FestivalTicketOrderStatus::Pending->value)->orWhere('expires_at', '>', now())))
            ->sum('quantity');
    }

    /** @return array{manage: bool, registrations: bool, schedule: bool, finance: bool, judging: bool, ticket_check_in: bool} */
    private function financePermissions(Request $request, Account $account, FestivalEdition $edition): array
    {
        $this->assertEdition($account, $edition);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $edition);
        abort_unless($permissions['finance'], 403);

        return $permissions;
    }

    private function assertEdition(Account $account, FestivalEdition $edition): void
    {
        abort_unless($edition->account_id === $account->id, 404);
    }

    private function assertAdmissionType(Account $account, FestivalEdition $edition, FestivalAdmissionType $admissionType): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($admissionType->account_id === $account->id && $admissionType->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition, ?string $message = null): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types'])
            ->with('status', $message ?? __('app.festival_admission_saved'));
    }
}
