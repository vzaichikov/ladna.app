<?php

namespace App\Http\Controllers;

use App\Enums\PromoCodeDiscountType;
use App\Http\Requests\StudioPromoCodeRequest;
use App\Models\Account;
use App\Models\StudioPromoCode;
use App\Support\Payments\PaymentAmounts;
use App\Support\ScheduleKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StudioPromoCodeController extends Controller
{
    public function index(Request $request, Account $account): View
    {
        $this->authorizeAccount($request, $account);
        $query = $request->string('q')->trim()->toString();
        $status = $request->string('status')->toString();
        $discountType = $request->string('discount_type')->toString();

        $promoCodes = $account->studioPromoCodes()
            ->withCount([
                'classPassPlans',
                'customerPurchases',
                'customerPurchases as uses_count' => fn (Builder $query): Builder => $query->reservingPromotionUse(),
            ])
            ->when($query !== '', fn (Builder $builder): Builder => $builder->where(fn (Builder $builder): Builder => $builder
                ->where('name', 'like', '%'.$query.'%')
                ->orWhere('code', 'like', '%'.$query.'%')))
            ->when($status === 'active', fn (Builder $builder): Builder => $builder->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $builder): Builder => $builder->where('is_active', false))
            ->when(in_array($discountType, array_column(PromoCodeDiscountType::cases(), 'value'), true), fn (Builder $builder): Builder => $builder->where('discount_type', $discountType))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('studio-promo-codes.index', compact('account', 'promoCodes', 'query', 'status', 'discountType'));
    }

    public function create(Request $request, Account $account): View
    {
        $this->authorizeAccount($request, $account);

        return view('studio-promo-codes.create', [
            'account' => $account,
            'studioPromoCode' => new StudioPromoCode([
                'currency' => $account->default_currency,
                'starts_at' => CarbonImmutable::now('UTC')->startOfHour(),
                'ends_at' => CarbonImmutable::now('UTC')->addMonth()->endOfHour(),
                'max_uses_per_identity' => 1,
                'is_active' => true,
            ]),
            ...$this->formData($account),
        ]);
    }

    public function store(StudioPromoCodeRequest $request, Account $account): RedirectResponse
    {
        $promoCode = DB::transaction(function () use ($request, $account): StudioPromoCode {
            $promoCode = $account->studioPromoCodes()->create($this->attributes($request, $account));
            $promoCode->classPassPlans()->sync($request->validated('class_pass_plan_ids'));

            return $promoCode;
        }, 3);

        return redirect()->route('dashboard.accounts.promo-codes.index', $account)
            ->with('status', __('app.promo_code_created', ['name' => $promoCode->name]));
    }

    public function edit(Request $request, Account $account, StudioPromoCode $studioPromoCode): View
    {
        $this->authorizePromoCode($request, $account, $studioPromoCode);
        $studioPromoCode->loadMissing('classPassPlans:id');

        return view('studio-promo-codes.edit', [
            'account' => $account,
            'studioPromoCode' => $studioPromoCode,
            ...$this->formData($account),
        ]);
    }

    public function update(StudioPromoCodeRequest $request, Account $account, StudioPromoCode $studioPromoCode): RedirectResponse
    {
        $this->authorizePromoCode($request, $account, $studioPromoCode);

        DB::transaction(function () use ($request, $account, $studioPromoCode): void {
            $lockedPromoCode = StudioPromoCode::query()->whereKey($studioPromoCode->getKey())->lockForUpdate()->firstOrFail();
            $lockedPromoCode->update($this->attributes($request, $account));
            $lockedPromoCode->classPassPlans()->sync($request->validated('class_pass_plan_ids'));
        }, 3);

        return redirect()->route('dashboard.accounts.promo-codes.index', $account)
            ->with('status', __('app.promo_code_updated', ['name' => $studioPromoCode->name]));
    }

    public function destroy(Request $request, Account $account, StudioPromoCode $studioPromoCode): RedirectResponse
    {
        $this->authorizePromoCode($request, $account, $studioPromoCode);

        DB::transaction(function () use ($studioPromoCode): void {
            $lockedPromoCode = StudioPromoCode::query()->whereKey($studioPromoCode->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedPromoCode->customerPurchases()->exists()) {
                throw ValidationException::withMessages(['promo_code' => __('app.promo_code_delete_history_block')]);
            }

            $lockedPromoCode->delete();
        }, 3);

        return redirect()->route('dashboard.accounts.promo-codes.index', $account)
            ->with('status', __('app.promo_code_deleted'));
    }

    private function authorizeAccount(Request $request, Account $account): void
    {
        abort_unless($account->isOwnedBy($request->user()), 403);
    }

    private function authorizePromoCode(Request $request, Account $account, StudioPromoCode $promoCode): void
    {
        $this->authorizeAccount($request, $account);
        abort_unless($promoCode->account_id === $account->id, 404);
    }

    /** @return array<string, mixed> */
    private function formData(Account $account): array
    {
        return [
            'classPassPlans' => $account->classPassPlans()
                ->whereIn('schedule_kind', ScheduleKindRegistry::classPassEligibleValues())
                ->where('currency', $account->default_currency)
                ->orderBy('schedule_kind')
                ->orderBy('name')
                ->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(StudioPromoCodeRequest $request, Account $account): array
    {
        $validated = $request->validated();
        $discountType = PromoCodeDiscountType::from($validated['discount_type']);

        return [
            'name' => $validated['name'],
            'code' => $validated['code'],
            'discount_type' => $discountType,
            'discount_value' => $discountType === PromoCodeDiscountType::Fixed
                ? PaymentAmounts::decimalToCents($validated['discount_amount'])
                : (int) $validated['discount_amount'],
            'currency' => $account->default_currency,
            'starts_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['starts_at'], $account->timezone)->utc(),
            'ends_at' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $validated['ends_at'], $account->timezone)->utc(),
            'max_total_uses' => $validated['max_total_uses'] ?? null,
            'max_uses_per_identity' => $validated['max_uses_per_identity'] ?? null,
            'is_active' => (bool) $validated['is_active'],
        ];
    }
}
