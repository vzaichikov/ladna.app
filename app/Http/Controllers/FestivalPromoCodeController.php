<?php

namespace App\Http\Controllers;

use App\Enums\FestivalTicketOrderStatus;
use App\Enums\PromoCodeDiscountType;
use App\Http\Requests\FestivalPromoCodeRequest;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalPromoCode;
use App\Support\Festivals\FestivalWorkspaceAccess;
use App\Support\Payments\PaymentAmounts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FestivalPromoCodeController extends Controller
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function index(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? (string) $request->query('status') : '',
        ];
        $promoCodes = FestivalPromoCode::query()
            ->where('account_id', $account->id)
            ->where('festival_edition_id', $festivalEdition->id)
            ->with('admissionTypes:id,name')
            ->withCount([
                'orders as referenced_orders_count',
                'orders as reserved_usage_count' => fn ($query) => $query
                    ->where('status', FestivalTicketOrderStatus::Pending->value)
                    ->where('expires_at', '>', now()),
                'orders as consumed_usage_count' => fn ($query) => $query->whereIn('status', [
                    FestivalTicketOrderStatus::Paid->value,
                    FestivalTicketOrderStatus::PaidRequiresRefund->value,
                    FestivalTicketOrderStatus::Refunded->value,
                ]),
            ])
            ->when($filters['q'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$filters['q'].'%')
                ->orWhere('code', 'like', '%'.$filters['q'].'%')))
            ->when($filters['status'] !== '', fn ($query) => $query->where('is_active', $filters['status'] === 'active'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('festivals.staff.promo-codes.index', [
            'account' => $account,
            'edition' => $festivalEdition,
            'promoCodes' => $promoCodes,
            'filters' => $filters,
            'workspacePermissions' => $permissions,
        ]);
    }

    public function create(Request $request, Account $account, FestivalEdition $festivalEdition): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $promoCode = new FestivalPromoCode([
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 10,
            'starts_at' => now()->toImmutable()->utc(),
            'ends_at' => $festivalEdition->ends_at->isFuture()
                ? $festivalEdition->ends_at->toImmutable()->utc()
                : now()->toImmutable()->addMonth()->utc(),
            'per_identity_usage_limit' => 1,
            'is_active' => true,
        ]);

        return $this->formView($account, $festivalEdition, $promoCode, $permissions);
    }

    public function store(FestivalPromoCodeRequest $request, Account $account, FestivalEdition $festivalEdition): RedirectResponse
    {
        $this->assertEdition($account, $festivalEdition);
        $data = $this->promoCodeData($request->validated(), $festivalEdition);

        DB::transaction(function () use ($account, $festivalEdition, $data): void {
            $admissionTypeIds = $data['admission_type_ids'];
            unset($data['admission_type_ids']);
            $promoCode = FestivalPromoCode::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $festivalEdition->id,
                'currency' => strtoupper($account->default_currency),
                ...$data,
            ]);
            $promoCode->admissionTypes()->sync($admissionTypeIds);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function edit(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPromoCode $festivalPromoCode): View
    {
        $permissions = $this->financePermissions($request, $account, $festivalEdition);
        $this->assertPromoCode($account, $festivalEdition, $festivalPromoCode);
        $festivalPromoCode->load('admissionTypes:id');

        return $this->formView($account, $festivalEdition, $festivalPromoCode, $permissions);
    }

    public function update(FestivalPromoCodeRequest $request, Account $account, FestivalEdition $festivalEdition, FestivalPromoCode $festivalPromoCode): RedirectResponse
    {
        $this->assertPromoCode($account, $festivalEdition, $festivalPromoCode);
        $data = $this->promoCodeData($request->validated(), $festivalEdition);

        DB::transaction(function () use ($account, $festivalEdition, $festivalPromoCode, $data): void {
            $promoCode = FestivalPromoCode::query()->whereKey($festivalPromoCode->id)->lockForUpdate()->firstOrFail();
            $this->assertPromoCode($account, $festivalEdition, $promoCode);
            $admissionTypeIds = $data['admission_type_ids'];
            unset($data['admission_type_ids']);
            $promoCode->update($data);
            $promoCode->admissionTypes()->sync($admissionTypeIds);
        }, 3);

        return $this->redirect($account, $festivalEdition);
    }

    public function toggle(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPromoCode $festivalPromoCode): RedirectResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $this->assertPromoCode($account, $festivalEdition, $festivalPromoCode);
        $festivalPromoCode->update(['is_active' => ! $festivalPromoCode->is_active]);

        return back()->with('status', __('app.promo_code_status_saved'));
    }

    public function destroy(Request $request, Account $account, FestivalEdition $festivalEdition, FestivalPromoCode $festivalPromoCode): RedirectResponse
    {
        $this->financePermissions($request, $account, $festivalEdition);
        $this->assertPromoCode($account, $festivalEdition, $festivalPromoCode);

        DB::transaction(function () use ($account, $festivalEdition, $festivalPromoCode): void {
            $promoCode = FestivalPromoCode::query()->whereKey($festivalPromoCode->id)->lockForUpdate()->firstOrFail();
            $this->assertPromoCode($account, $festivalEdition, $promoCode);

            if ($promoCode->hasUsageHistory()) {
                throw ValidationException::withMessages(['promo_code' => __('app.promo_code_delete_history_block')]);
            }

            $promoCode->delete();
        }, 3);

        return $this->redirect($account, $festivalEdition, __('app.promo_code_deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function promoCodeData(array $data, FestivalEdition $edition): array
    {
        $discountType = PromoCodeDiscountType::from($data['discount_type']);
        $data['discount_value'] = $discountType === PromoCodeDiscountType::Fixed
            ? (int) PaymentAmounts::decimalToCents($data['discount_value'])
            : (int) $data['discount_value'];
        $data['starts_at'] = CarbonImmutable::parse((string) $data['starts_at'], $edition->timezone)->utc();
        $data['ends_at'] = CarbonImmutable::parse((string) $data['ends_at'], $edition->timezone)->utc();

        return $data;
    }

    /** @param array<string, bool> $permissions */
    private function formView(Account $account, FestivalEdition $edition, FestivalPromoCode $promoCode, array $permissions): View
    {
        return view('festivals.staff.promo-codes.form', [
            'account' => $account,
            'edition' => $edition,
            'promoCode' => $promoCode,
            'admissionTypes' => $edition->admissionTypes()->get(['id', 'name', 'delivery_mode', 'is_active']),
            'workspacePermissions' => $permissions,
        ]);
    }

    /** @return array<string, bool> */
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

    private function assertPromoCode(Account $account, FestivalEdition $edition, FestivalPromoCode $promoCode): void
    {
        $this->assertEdition($account, $edition);
        abort_unless($promoCode->account_id === $account->id && $promoCode->festival_edition_id === $edition->id, 404);
    }

    private function redirect(Account $account, FestivalEdition $edition, ?string $message = null): RedirectResponse
    {
        return redirect()->route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition])
            ->with('status', $message ?? __('app.promo_code_saved'));
    }
}
