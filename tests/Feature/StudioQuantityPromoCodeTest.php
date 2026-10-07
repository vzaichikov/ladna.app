<?php

namespace Tests\Feature;

use App\Enums\PromoCodeDiscountType;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\StudioPromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudioQuantityPromoCodeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_a_quantity_offer_for_all_ordinary_formats_without_an_amount(): void
    {
        [$owner, $account, $plan] = $this->context();
        $privatePlan = ClassPassPlan::factory()->for($account)->create(['schedule_kind' => ScheduleKind::PrivateLesson]);
        $rentalPlan = ClassPassPlan::factory()->for($account)->create(['schedule_kind' => ScheduleKind::RoomRental]);

        $this->actingAs($owner)->get(route('dashboard.accounts.promo-codes.create', $account))
            ->assertOk()
            ->assertSee('value="buy_x_get_y"', false)
            ->assertSee('name="buy_quantity"', false)
            ->assertSee('name="free_quantity"', false);

        $this->actingAs($owner)->post(route('dashboard.accounts.promo-codes.store', $account), $this->payload($plan, [
            'discount_amount' => 'ignored for a quantity offer',
            'class_pass_plan_ids' => [$plan->id, $privatePlan->id, $rentalPlan->id],
        ]))->assertRedirect(route('dashboard.accounts.promo-codes.index', $account));

        $promoCode = $account->studioPromoCodes()->sole();
        $this->assertSame(PromoCodeDiscountType::BuyXGetY, $promoCode->discount_type);
        $this->assertSame(0, $promoCode->discount_value);
        $this->assertSame(9, $promoCode->buy_quantity);
        $this->assertSame(1, $promoCode->free_quantity);
        $this->assertSame(3, $promoCode->classPassPlans()->count());

        $this->get(route('dashboard.accounts.promo-codes.index', $account))
            ->assertOk()
            ->assertSee(__('app.promo_code_buy_get_label', ['buy' => 9, 'free' => 1]));
        $this->get(route('dashboard.accounts.promo-codes.edit', [$account, $promoCode]))
            ->assertOk()
            ->assertSee('value="9"', false);
    }

    public function test_switching_back_to_an_amount_discount_clears_the_quantities(): void
    {
        [$owner, $account, $plan] = $this->context();
        $promoCode = StudioPromoCode::factory()->for($account)->create([
            'discount_type' => PromoCodeDiscountType::BuyXGetY,
            'discount_value' => 0,
            'buy_quantity' => 9,
            'free_quantity' => 1,
        ]);
        $promoCode->classPassPlans()->attach($plan);

        $this->actingAs($owner)->put(route('dashboard.accounts.promo-codes.update', [$account, $promoCode]), $this->payload($plan, [
            'discount_type' => PromoCodeDiscountType::Percent->value,
            'discount_amount' => 15,
            'buy_quantity' => 0,
            'free_quantity' => 'obsolete input',
        ]))->assertRedirect(route('dashboard.accounts.promo-codes.index', $account));

        $promoCode->refresh();
        $this->assertSame(PromoCodeDiscountType::Percent, $promoCode->discount_type);
        $this->assertSame(15, $promoCode->discount_value);
        $this->assertNull($promoCode->buy_quantity);
        $this->assertNull($promoCode->free_quantity);
    }

    #[DataProvider('invalidQuantities')]
    public function test_quantity_offers_require_positive_integer_quantities(string $field, mixed $value): void
    {
        [$owner, $account, $plan] = $this->context();

        $this->actingAs($owner)->post(route('dashboard.accounts.promo-codes.store', $account), $this->payload($plan, [
            $field => $value,
        ]))->assertSessionHasErrors($field);

        $this->assertSame(0, $account->studioPromoCodes()->count());
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidQuantities(): array
    {
        return [
            'missing paid quantity' => ['buy_quantity', null],
            'zero paid quantity' => ['buy_quantity', 0],
            'negative paid quantity' => ['buy_quantity', -1],
            'fractional paid quantity' => ['buy_quantity', 1.5],
            'missing free quantity' => ['free_quantity', null],
            'zero free quantity' => ['free_quantity', 0],
            'negative free quantity' => ['free_quantity', -2],
            'fractional free quantity' => ['free_quantity', 1.5],
        ];
    }

    public function test_trial_inactive_and_disabled_format_plans_cannot_be_selected_for_a_quantity_offer(): void
    {
        [$owner, $account, $plan] = $this->context();
        $account->update(['enabled_schedule_kinds' => [ScheduleKind::GroupClass->value]]);
        $unavailablePlans = [
            ClassPassPlan::factory()->for($account)->create(['is_trial' => true]),
            ClassPassPlan::factory()->for($account)->create(['is_active' => false]),
            ClassPassPlan::factory()->for($account)->create(['schedule_kind' => ScheduleKind::PrivateLesson]),
        ];

        foreach ($unavailablePlans as $unavailablePlan) {
            $this->actingAs($owner)->post(route('dashboard.accounts.promo-codes.store', $account), $this->payload($plan, [
                'class_pass_plan_ids' => [$plan->id, $unavailablePlan->id],
            ]))->assertSessionHasErrors('class_pass_plan_ids');
        }

        $this->assertSame(0, $account->studioPromoCodes()->count());
    }

    /** @return array{User, Account, ClassPassPlan} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH', 'timezone' => 'Europe/Kyiv']);
        $account->addOwner($owner);
        $plan = ClassPassPlan::factory()->for($account)->create();

        return [$owner, $account, $plan];
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(ClassPassPlan $plan, array $overrides = []): array
    {
        return [
            'name' => 'Nine plus one',
            'code' => 'NINE-PLUS-ONE',
            'discount_type' => PromoCodeDiscountType::BuyXGetY->value,
            'buy_quantity' => 9,
            'free_quantity' => 1,
            'starts_at' => now('Europe/Kyiv')->subHour()->format('Y-m-d\TH:i'),
            'ends_at' => now('Europe/Kyiv')->addMonth()->format('Y-m-d\TH:i'),
            'max_total_uses' => null,
            'max_uses_per_identity' => 1,
            'class_pass_plan_ids' => [$plan->id],
            'is_active' => 1,
            ...$overrides,
        ];
    }
}
