<?php

namespace Tests\Feature;

use App\Enums\CustomerPurchaseStatus;
use App\Enums\PromoCodeDiscountType;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\StudioPromoCode;
use App\Support\Promotions\StudioPromoCodeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudioPromoCodeCartQuoteTest extends TestCase
{
    use DatabaseTransactions;

    #[DataProvider('ninePlusOneQuantities')]
    public function test_nine_plus_one_counts_total_issued_quantity_and_keeps_unit_keys(int $quantity, int $freeQuantity): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $plans = array_fill(0, $quantity, $plan);

        if ($freeQuantity === 0) {
            $this->assertInvalidQuote($account, $customer, $plans, $promoCode->code);

            return;
        }

        $result = app(StudioPromoCodeService::class)->quoteCart($account, $customer, $plans, strtolower($promoCode->code));
        $quote = $result['quote'];

        $this->assertSame($promoCode->id, $result['promoCode']->id);
        $this->assertSame($quantity * 100000, $quote->subtotalCents);
        $this->assertSame($freeQuantity * 100000, $quote->discountCents);
        $this->assertSame(($quantity - $freeQuantity) * 100000, $quote->totalCents);
        $this->assertSame($freeQuantity === 1 ? [9] : [9, 19], array_keys(array_filter($quote->lineDiscounts)));
        $this->assertSame(0, $promoCode->customerPurchases()->count());
    }

    /** @return array<string, array{int, int}> */
    public static function ninePlusOneQuantities(): array
    {
        return [
            'nine are not eligible' => [9, 0],
            'ten pay for nine' => [10, 1],
            'eleven pay for ten' => [11, 1],
            'twenty pay for eighteen' => [20, 2],
        ];
    }

    public function test_configurable_offer_counts_different_plans_separately_with_regular_items_at_full_price(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $promoCode->update(['buy_quantity' => 3, 'free_quantity' => 2]);
        $secondPlan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental,
            'price_cents' => 200000,
        ]);
        $regularPlan = ClassPassPlan::factory()->for($account)->create(['price_cents' => 50000]);
        $promoCode->classPassPlans()->attach($secondPlan);
        $plans = [...array_fill(0, 10, $plan), ...array_fill(0, 5, $secondPlan), $regularPlan];

        $quote = app(StudioPromoCodeService::class)->quoteCart($account, $customer, $plans, $promoCode->code)['quote'];

        $this->assertSame(2050000, $quote->subtotalCents);
        $this->assertSame(2000000, $quote->eligibleSubtotalCents);
        $this->assertSame(800000, $quote->discountCents);
        $this->assertSame(1250000, $quote->totalCents);
        $this->assertSame([3, 4, 8, 9, 13, 14], array_keys(array_filter($quote->lineDiscounts)));
        $this->assertArrayNotHasKey(15, $quote->lineDiscounts);

        $this->assertInvalidQuote($account, $customer, [...array_fill(0, 3, $plan), ...array_fill(0, 2, $secondPlan)], $promoCode->code);
    }

    public function test_trial_passes_do_not_count_toward_quantity_offers(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $trialPlan = ClassPassPlan::factory()->for($account)->create(['is_trial' => true]);
        $promoCode->classPassPlans()->attach($trialPlan);

        $this->assertInvalidQuote($account, $customer, [...array_fill(0, 9, $plan), $trialPlan], $promoCode->code);
        $this->assertInvalidQuote($account, $customer, array_fill(0, 10, $trialPlan), $promoCode->code);

        $quote = app(StudioPromoCodeService::class)->quoteCart($account, $customer, [...array_fill(0, 10, $plan), $trialPlan], $promoCode->code)['quote'];
        $this->assertSame(100000, $quote->discountCents);
        $this->assertArrayNotHasKey(10, $quote->lineDiscounts);
    }

    public function test_inactive_or_disabled_plans_do_not_qualify(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $plan->update(['is_active' => false]);
        $this->assertInvalidQuote($account, $customer, array_fill(0, 10, $plan), $promoCode->code);

        $plan->update(['is_active' => true]);
        $account->update(['enabled_schedule_kinds' => [ScheduleKind::GroupClass->value]]);
        $this->assertInvalidQuote($account, $customer, array_fill(0, 10, $plan), $promoCode->code);
    }

    public function test_fixed_and_percentage_promotions_apply_once_to_the_eligible_cart_and_keep_single_plan_keys(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $regularPlan = ClassPassPlan::factory()->for($account)->create(['price_cents' => 50000]);
        $promoCode->update(['discount_type' => PromoCodeDiscountType::Fixed, 'discount_value' => 5000]);
        $service = app(StudioPromoCodeService::class);
        $quote = $service->quoteCart($account, $customer, [4 => $plan, 8 => $regularPlan, 12 => $plan], $promoCode->code)['quote'];

        $this->assertSame(5000, $quote->discountCents);
        $this->assertSame(245000, $quote->totalCents);
        $this->assertSame([4 => 2500, 12 => 2500], $quote->lineDiscounts);
        $this->assertSame([$plan->id => 5000], $service->quote($account, $plan, $customer, $promoCode->code)['quote']->lineDiscounts);

        $promoCode->update(['discount_type' => PromoCodeDiscountType::Percent, 'discount_value' => 25]);
        $quote = $service->quoteCart($account, $customer, [4 => $plan, 8 => $regularPlan, 12 => $plan], $promoCode->code)['quote'];
        $this->assertSame(50000, $quote->discountCents);
        $this->assertSame(200000, $quote->totalCents);
        $this->assertSame([4 => 25000, 12 => 25000], $quote->lineDiscounts);
    }

    public function test_pending_purchase_reserves_one_use_and_preview_does_not_reserve_any(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $plans = array_fill(0, 10, $plan);
        $service = app(StudioPromoCodeService::class);
        $service->quoteCart($account, $customer, $plans, $promoCode->code);
        $service->quoteCart($account, $customer, $plans, $promoCode->code);
        $this->assertSame(0, $promoCode->customerPurchases()->count());

        $purchase = CustomerPurchase::factory()->for($account)->for($customer)->for($plan)->create([
            'studio_promo_code_id' => $promoCode->id,
            'status' => CustomerPurchaseStatus::PaymentStarted,
            'expires_at' => now()->addHour(),
        ]);
        $this->assertInvalidQuote($account, $customer, $plans, $promoCode->code);

        $purchase->update(['status' => CustomerPurchaseStatus::PaymentFailed]);
        $this->assertSame(100000, $service->quoteCart($account, $customer, $plans, $promoCode->code)['quote']->discountCents);

        $purchase->update(['status' => CustomerPurchaseStatus::PaymentStarted, 'expires_at' => now()->subHour()]);
        $this->assertSame(100000, $service->quoteCart($account, $customer, $plans, $promoCode->code)['quote']->discountCents);
    }

    public function test_usage_limits_match_other_customer_records_with_the_same_identity(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $email = $customer->email;
        $phone = $customer->phone;
        $service = app(StudioPromoCodeService::class);
        $promotion = $service->quoteCart($account, $customer, array_fill(0, 10, $plan), $promoCode->code);
        CustomerPurchase::factory()->for($account)->for($customer)->for($plan)->create([
            'studio_promo_code_id' => $promoCode->id,
            'promo_email_hash' => $promotion['emailHash'],
            'promo_phone_hash' => $promotion['phoneHash'],
            'status' => CustomerPurchaseStatus::PaymentPaid,
        ]);
        $customer->update(['email' => fake()->unique()->safeEmail(), 'phone' => null]);
        $otherCustomer = Customer::factory()->for($account)->create(['email' => $email, 'phone' => $phone]);

        $this->assertInvalidQuote($account, $otherCustomer, array_fill(0, 10, $plan), $promoCode->code);

        $promoCode->update(['max_uses_per_identity' => null, 'max_total_uses' => 1]);
        $this->assertInvalidQuote($account, $otherCustomer, array_fill(0, 10, $plan), $promoCode->code);
    }

    public function test_locked_single_pass_checkout_observes_the_total_quota_reserved_by_an_aggregate_purchase(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $promoCode->update([
            'discount_type' => PromoCodeDiscountType::Fixed,
            'discount_value' => 1000,
            'max_total_uses' => 1,
            'max_uses_per_identity' => null,
        ]);
        $otherCustomer = Customer::factory()->for($account)->create();
        $purchase = CustomerPurchase::factory()->for($account)->for($otherCustomer)->create([
            'class_pass_plan_id' => null,
            'studio_promo_code_id' => $promoCode->id,
            'status' => CustomerPurchaseStatus::PaymentStarted,
            'expires_at' => now()->addHour(),
        ]);
        $service = app(StudioPromoCodeService::class);

        try {
            $service->quote($account, $plan, $customer, $promoCode->code, true);
            $this->fail('A single-pass checkout ignored an aggregate purchase reservation.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('app.promo_code_total_limit_reached')], $exception->errors()['promo_code']);
        }

        $purchase->update(['expires_at' => now()->subHour()]);
        $this->assertSame(99000, $service->quote($account, $plan, $customer, $promoCode->code, true)['quote']->totalCents);
        $this->assertSame(199000, $service->quoteCart($account, $customer, [$plan, $plan], $promoCode->code, true)['quote']->totalCents);
    }

    public function test_tenant_currency_and_active_window_boundaries_are_preserved(): void
    {
        [$account, $customer, $plan, $promoCode] = $this->context();
        $foreignAccount = Account::factory()->create();
        $foreignCustomer = Customer::factory()->for($foreignAccount)->create();
        $foreignPlan = ClassPassPlan::factory()->for($foreignAccount)->create();

        $this->assertInvalidQuote($account, $foreignCustomer, array_fill(0, 10, $plan), $promoCode->code);
        $this->assertInvalidQuote($account, $customer, array_fill(0, 10, $foreignPlan), $promoCode->code);
        $this->assertInvalidQuote($foreignAccount, $foreignCustomer, array_fill(0, 10, $foreignPlan), $promoCode->code);

        $plan->update(['currency' => 'EUR']);
        $this->assertInvalidQuote($account, $customer, array_fill(0, 10, $plan), $promoCode->code);
        $plan->update(['currency' => 'UAH']);

        foreach ([['is_active' => false], ['is_active' => true, 'starts_at' => now()->addHour()], ['starts_at' => now()->subDay(), 'ends_at' => now()->subHour()]] as $changes) {
            $promoCode->update($changes);
            $this->assertInvalidQuote($account, $customer, array_fill(0, 10, $plan), $promoCode->code);
        }
    }

    /** @return array{Account, Customer, ClassPassPlan, StudioPromoCode} */
    private function context(): array
    {
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson,
            'price_cents' => 100000,
        ]);
        $promoCode = StudioPromoCode::factory()->for($account)->create([
            'discount_type' => PromoCodeDiscountType::BuyXGetY,
            'discount_value' => 0,
            'buy_quantity' => 9,
            'free_quantity' => 1,
        ]);
        $promoCode->classPassPlans()->attach($plan);

        return [$account, $customer, $plan, $promoCode];
    }

    /** @param array<int, ClassPassPlan> $plans */
    private function assertInvalidQuote(Account $account, Customer $customer, array $plans, string $code): void
    {
        try {
            app(StudioPromoCodeService::class)->quoteCart($account, $customer, $plans, $code);
            $this->fail('An ineligible promotion quote was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promo_code', $exception->errors());
        }
    }
}
