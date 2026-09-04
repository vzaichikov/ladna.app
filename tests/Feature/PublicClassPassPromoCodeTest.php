<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteCustomerPurchase;
use App\Actions\Payments\CreateCustomerPurchase;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\IntegrationProvider;
use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\StudioPromoCode;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublicClassPassPromoCodeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quote_returns_the_authoritative_discount_and_rejects_an_ineligible_plan(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $promoCode = $this->promoCode($account, $plan, [
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 25,
        ]);

        $this->actingAs($customer, 'customer')
            ->postJson(route('public.class-pass-plans.promo-code.quote', [$account->slug, $location->slug, $plan->slug]), [
                'promo_code' => strtolower($promoCode->code),
            ])
            ->assertOk()
            ->assertJsonPath('subtotal_cents', 180000)
            ->assertJsonPath('eligible_subtotal_cents', 180000)
            ->assertJsonPath('discount_cents', 45000)
            ->assertJsonPath('total_cents', 135000)
            ->assertJsonPath('code', $promoCode->code)
            ->assertJsonPath('requires_payment', true);

        $otherPlan = ClassPassPlan::factory()->for($account)->create([
            'slug' => 'other-checkout-pass',
            'price_cents' => 90000,
        ]);
        $otherPlan->classTypes()->sync($plan->classTypes()->pluck('class_types.id'));

        $this->postJson(route('public.class-pass-plans.promo-code.quote', [$account->slug, $location->slug, $otherPlan->slug]), [
            'promo_code' => $promoCode->code,
        ])->assertUnprocessable()->assertJsonValidationErrors('promo_code');
    }

    public function test_full_discount_completes_without_a_gateway_and_preserves_list_and_paid_amounts(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $promoCode = $this->promoCode($account, $plan, [
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 100,
        ]);
        $checkoutUrl = route('public.class-pass-plans.checkout', [$account->slug, $location->slug, $plan->slug]);

        $this->actingAs($customer, 'customer')
            ->post(route('public.class-pass-plans.checkout.store', [$account->slug, $location->slug, $plan->slug]), [
                'studio_rules_accepted' => '1',
                'promo_code' => $promoCode->code,
            ])
            ->assertRedirect($checkoutUrl);

        $this->get($checkoutUrl)
            ->assertOk()
            ->assertSee($promoCode->code);

        $purchase = $customer->purchases()->with('customerClassPass')->sole();
        $this->assertSame(180000, $purchase->subtotal_cents);
        $this->assertSame(180000, $purchase->discount_cents);
        $this->assertSame(0, $purchase->amount_cents);
        $this->assertSame($promoCode->id, $purchase->studio_promo_code_id);
        $this->assertTrue($purchase->isPaid());
        $this->assertNotNull($purchase->customerClassPass);
        $this->assertSame(180000, $purchase->customerClassPass->price_cents);
        $this->assertSame(0, $purchase->customerClassPass->paid_amount_cents);
        $this->assertTrue($purchase->customerClassPass->is_paid);
    }

    public function test_discounted_payment_issues_a_pass_with_list_price_and_actual_paid_amount(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $promoCode = $this->promoCode($account, $plan, [
            'discount_type' => PromoCodeDiscountType::Fixed,
            'discount_value' => 30000,
        ]);
        $purchase = app(CreateCustomerPurchase::class)->execute(
            $account,
            $customer,
            $plan,
            IntegrationProvider::Monopay,
            $location,
            $promoCode->code,
        );

        $completed = app(CompleteCustomerPurchase::class)->execute($purchase, new PaymentCallbackResult(
            orderId: $purchase->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: 150000,
            currency: 'UAH',
            paidAt: now(),
        ));
        $classPass = $completed->customerClassPass;

        $this->assertNotNull($classPass);
        $this->assertSame(180000, $classPass->price_cents);
        $this->assertSame(150000, $classPass->paid_amount_cents);
        $this->assertTrue($classPass->is_paid);
    }

    public function test_pending_purchase_reserves_identity_quota_and_failure_releases_it(): void
    {
        [$account, $location, $plan, $customer] = $this->checkoutContext();
        $promoCode = $this->promoCode($account, $plan, ['max_uses_per_identity' => 1]);
        $action = app(CreateCustomerPurchase::class);
        $first = $action->execute($account, $customer, $plan, IntegrationProvider::Monopay, $location, $promoCode->code);

        try {
            $action->execute($account, $customer, $plan, IntegrationProvider::Monopay, $location, $promoCode->code);
            $this->fail('A reserved promotion identity was accepted twice. Charming.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promo_code', $exception->errors());
        }

        $first->update([
            'status' => CustomerPurchaseStatus::PaymentFailed,
            'failed_at' => now(),
        ]);
        $second = $action->execute($account, $customer, $plan, IntegrationProvider::Monopay, $location, $promoCode->code);

        $this->assertSame($promoCode->id, $second->studio_promo_code_id);
    }

    public function test_legacy_buy_entry_points_redirect_to_the_canonical_checkout(): void
    {
        [$account, $location, $plan] = $this->checkoutContext();
        $canonicalUrl = route('public.class-pass-plans.checkout', [$account->slug, $location->slug, $plan->slug]);

        $this->get(route('public.class-pass-plans.buy', [$account->slug, $location->slug, $plan->slug]))
            ->assertRedirect($canonicalUrl);
        $this->post(route('public.class-pass-plans.purchase', [$account->slug, $location->slug, $plan->slug]))
            ->assertRedirect($canonicalUrl)
            ->assertStatus(303);
    }

    /** @return array{0: Account, 1: Location, 2: ClassPassPlan, 3: Customer} */
    private function checkoutContext(): array
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'default_currency' => 'UAH',
            'studio_rules_html' => '<p>Checkout rules</p>',
            'public_offer_html' => '<p>Checkout offer</p>',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'promo-studio']);
        $classType = ClassType::factory()->for($account)->create(['schedule_kind' => 'group_class']);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Promotion pass',
            'slug' => 'promotion-pass',
            'price_cents' => 180000,
            'currency' => 'UAH',
            'sessions_count' => 8,
        ]);
        $plan->classTypes()->sync([$classType->id]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Promotion Customer',
            'email' => 'promotion-customer-'.fake()->unique()->numberBetween(1000, 9999).'@example.com',
            'phone' => '+38050'.fake()->unique()->numerify('#######'),
        ]);

        return [$account, $location, $plan, $customer];
    }

    /** @param array<string, mixed> $overrides */
    private function promoCode(Account $account, ClassPassPlan $plan, array $overrides = []): StudioPromoCode
    {
        $promoCode = StudioPromoCode::factory()->for($account)->create($overrides);
        $promoCode->classPassPlans()->attach($plan);

        return $promoCode;
    }
}
