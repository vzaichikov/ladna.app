<?php

namespace Tests\Feature;

use App\Enums\PromoCodeDiscountType;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\StudioPromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StudioPromoCodeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_create_a_plan_scoped_promo_code_and_codes_are_account_scoped(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'default_currency' => 'UAH',
            'timezone' => 'Europe/Kyiv',
        ]);
        $account->addOwner($owner);
        $plan = ClassPassPlan::factory()->for($account)->create();
        $privatePlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Private lesson offer',
            'schedule_kind' => ScheduleKind::PrivateLesson,
        ]);
        $rentalPlan = ClassPassPlan::factory()->for($account)->create([
            'name' => 'Room rental offer',
            'schedule_kind' => ScheduleKind::RoomRental,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.promo-codes.create', $account))
            ->assertOk()
            ->assertSee($plan->name)
            ->assertSee($privatePlan->name)
            ->assertSee($rentalPlan->name)
            ->assertSee(__('app.group_classes'))
            ->assertSee(__('app.private_lessons'))
            ->assertSee(__('app.room_rentals'));

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.promo-codes.store', $account), $this->payload($plan, [
                'name' => 'September launch',
                'code' => ' autumn-25 ',
                'discount_type' => PromoCodeDiscountType::Fixed->value,
                'discount_amount' => '25.50',
            ]))
            ->assertRedirect(route('dashboard.accounts.promo-codes.index', $account));

        $promoCode = StudioPromoCode::query()->whereBelongsTo($account)->sole();
        $this->assertSame('AUTUMN-25', $promoCode->code);
        $this->assertSame(2550, $promoCode->discount_value);
        $this->assertTrue($promoCode->classPassPlans()->whereKey($plan)->exists());

        $otherAccount = Account::factory()->create(['default_currency' => 'UAH']);
        $otherOwner = User::factory()->create();
        $otherAccount->addOwner($otherOwner);
        $otherPlan = ClassPassPlan::factory()->for($otherAccount)->create();

        $this->actingAs($otherOwner)
            ->post(route('dashboard.accounts.promo-codes.store', $otherAccount), $this->payload($otherPlan, [
                'code' => 'autumn-25',
            ]))
            ->assertRedirect(route('dashboard.accounts.promo-codes.index', $otherAccount));

        $this->assertSame(2, StudioPromoCode::query()->where('code', 'AUTUMN-25')->count());
    }

    public function test_only_owner_can_manage_codes_and_foreign_plans_are_rejected(): void
    {
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $plan = ClassPassPlan::factory()->for($account)->create();
        $otherAccount = Account::factory()->create(['default_currency' => 'UAH']);
        $foreignPlan = ClassPassPlan::factory()->for($otherAccount)->create();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('dashboard.accounts.promo-codes.store', $account), $this->payload($plan))
            ->assertForbidden();

        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.promo-codes.store', $account), $this->payload($foreignPlan))
            ->assertSessionHasErrors('class_pass_plan_ids');
    }

    public function test_code_can_only_be_deleted_before_a_purchase_references_it(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $plan = ClassPassPlan::factory()->for($account)->create();
        $promoCode = StudioPromoCode::factory()->for($account)->create();
        $promoCode->classPassPlans()->attach($plan);
        $customer = Customer::factory()->for($account)->create();
        CustomerPurchase::factory()->for($account)->for($customer)->for($plan)->create([
            'studio_promo_code_id' => $promoCode->id,
            'promo_name' => $promoCode->name,
            'promo_code' => $promoCode->code,
            'promo_discount_type' => $promoCode->discount_type->value,
            'promo_discount_value' => $promoCode->discount_value,
            'status' => 'payment_failed',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.promo-codes.index', $account))
            ->assertOk()
            ->assertDontSee('action="'.route('dashboard.accounts.promo-codes.destroy', [$account, $promoCode]).'"', false)
            ->assertSee(__('app.promo_code_used_help'));

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.promo-codes.destroy', [$account, $promoCode]))
            ->assertSessionHasErrors('promo_code');

        $this->assertModelExists($promoCode);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(ClassPassPlan $plan, array $overrides = []): array
    {
        return [
            'name' => 'Autumn offer',
            'code' => 'AUTUMN-25',
            'discount_type' => PromoCodeDiscountType::Percent->value,
            'discount_amount' => 25,
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
