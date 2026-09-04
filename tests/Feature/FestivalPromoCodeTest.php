<?php

namespace Tests\Feature;

use App\Actions\Festivals\CreateFestivalTicketOrder;
use App\Actions\Festivals\ResolveFestivalGuest;
use App\Enums\FestivalTicketOrderStatus;
use App\Enums\PromoCodeDiscountType;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Models\FestivalPromoCode;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\User;
use App\Support\Festivals\FestivalPromoCodePricing;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class FestivalPromoCodeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_finance_owner_manages_an_edition_scoped_promo_code_and_used_codes_cannot_be_deleted(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.promo-codes.store', [$account, $edition]), $this->promoPayload($type, [
                'name' => 'Balcony launch',
                'code' => ' balcony-25 ',
                'discount_type' => PromoCodeDiscountType::Fixed->value,
                'discount_value' => '25.50',
            ]))
            ->assertRedirect(route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition]));

        $promoCode = FestivalPromoCode::query()->whereBelongsTo($edition, 'edition')->sole();
        $this->assertSame('BALCONY-25', $promoCode->code);
        $this->assertSame(2550, $promoCode->discount_value);
        $this->assertTrue($promoCode->admissionTypes()->whereKey($type->id)->exists());
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition]))
            ->assertOk()
            ->assertSee('BALCONY-25');
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.promo-codes.edit', [$account, $edition, $promoCode]))
            ->assertOk()
            ->assertSee('BALCONY-25');

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)
            ->post(route('dashboard.accounts.festivals.promo-codes.store', [$account, $edition]), $this->promoPayload($type))
            ->assertForbidden();

        FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_promo_code_id' => $promoCode->id,
            'promo_name' => $promoCode->name,
            'promo_code' => $promoCode->code,
            'promo_discount_type' => $promoCode->discount_type->value,
            'promo_discount_value' => $promoCode->discount_value,
            'subtotal_cents' => 30000,
            'discount_cents' => 2550,
            'amount_cents' => 27450,
        ]);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.promo-codes.index', [$account, $edition]))
            ->delete(route('dashboard.accounts.festivals.promo-codes.destroy', [$account, $edition, $promoCode]))
            ->assertSessionHasErrors('promo_code');
        $this->assertModelExists($promoCode);
    }

    public function test_quote_and_final_order_apply_early_bird_pricing_before_the_selected_type_discount(): void
    {
        [$account, $edition] = $this->festival();
        $eligibleType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Early balcony',
            'inventory' => 20,
            'price_cents' => 15000,
            'early_bird_price_cents' => 10000,
            'early_bird_ends_at' => now()->addDay(),
            'early_bird_quota' => 10,
        ]);
        $regularType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Floor',
            'inventory' => 20,
            'price_cents' => 5000,
        ]);
        $promoCode = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'HALF',
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 50,
        ]);
        $promoCode->admissionTypes()->attach($eligibleType);

        $this->postJson(route('public.festivals.admission.promo-code', [$account->slug, $edition->slug]), [
            'promo_code' => ' half ',
            'buyer_email' => 'viewer@example.com',
            'buyer_phone' => '+380501112233',
            'items' => [$eligibleType->id => 2, $regularType->id => 1],
        ])
            ->assertOk()
            ->assertJsonPath('subtotal_cents', 25000)
            ->assertJsonPath('eligible_subtotal_cents', 20000)
            ->assertJsonPath('discount_cents', 10000)
            ->assertJsonPath('total_cents', 15000)
            ->assertJsonPath('promo_code', 'HALF')
            ->assertJsonPath('line_discounts.'.$eligibleType->id, 10000);

        $guest = FestivalPortalUser::factory()->guest()->for($account)->create([
            'email' => 'viewer@example.com',
            'email_normalized' => 'viewer@example.com',
            'phone' => '+380501112233',
            'phone_normalized' => '+380501112233',
        ]);
        $order = $this->createOrderAction($account)->execute($edition, [
            'buyer_name' => $guest->displayName(),
            'buyer_email' => $guest->email,
            'buyer_phone' => $guest->phone,
            'provider' => 'monopay',
            'promo_code' => 'HALF',
            'items' => [
                ['admission_type_id' => $eligibleType->id, 'quantity' => 2],
                ['admission_type_id' => $regularType->id, 'quantity' => 1],
            ],
        ], $guest);

        $this->assertSame(25000, $order->subtotal_cents);
        $this->assertSame(10000, $order->discount_cents);
        $this->assertSame(15000, $order->amount_cents);
        $this->assertSame($promoCode->id, $order->festival_promo_code_id);
        $this->assertSame('HALF', $order->promo_code);
        $this->assertNotNull($order->promo_email_hash);
        $this->assertNotNull($order->promo_phone_hash);
        $this->assertSame(10000, $order->items->firstWhere('festival_admission_type_id', $eligibleType->id)?->discount_cents);
        $this->assertSame(10000, $order->items->firstWhere('festival_admission_type_id', $eligibleType->id)?->final_total_cents);
        $this->assertSame(5000, $order->items->firstWhere('festival_admission_type_id', $regularType->id)?->final_total_cents);
    }

    public function test_management_validation_scopes_codes_and_admission_types_to_the_owning_edition(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id]);
        $promoCode = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'SAME-CODE',
        ]);
        $otherEdition = FestivalEdition::factory()
            ->published()
            ->for(FestivalSeries::factory()->for($account))
            ->create([
                'account_id' => $account->id,
                'starts_at' => now()->addMonths(2),
                'ends_at' => now()->addMonths(2)->addHours(6),
            ]);
        $otherEditionType = FestivalAdmissionType::factory()->for($otherEdition)->create(['account_id' => $account->id]);
        [$foreignAccount, $foreignEdition] = $this->festival();
        $foreignType = FestivalAdmissionType::factory()->for($foreignEdition)->create(['account_id' => $foreignAccount->id]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.promo-codes.store', [$account, $edition]), $this->promoPayload($type, [
                'code' => ' same-code ',
            ]))
            ->assertSessionHasErrors('code');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.promo-codes.store', [$account, $edition]), $this->promoPayload($foreignType, [
                'code' => 'FOREIGN-TYPE',
            ]))
            ->assertSessionHasErrors('admission_type_ids.0');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.promo-codes.store', [$account, $edition]), $this->promoPayload($type, [
                'code' => 'BAD-DATES',
                'starts_at' => now()->addDay()->timezone('Europe/Kyiv')->format('Y-m-d\TH:i'),
                'ends_at' => now()->timezone('Europe/Kyiv')->format('Y-m-d\TH:i'),
            ]))
            ->assertSessionHasErrors('ends_at');

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.promo-codes.store', [$account, $otherEdition]), $this->promoPayload($otherEditionType, [
                'code' => 'same-code',
            ]))
            ->assertRedirect(route('dashboard.accounts.festivals.promo-codes.index', [$account, $otherEdition]));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.promo-codes.edit', [$account, $otherEdition, $promoCode]))
            ->assertNotFound();
    }

    public function test_quote_rejects_inactive_expired_and_ineligible_codes(): void
    {
        [$account, $edition] = $this->festival();
        $selectedType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 10,
            'price_cents' => 10000,
        ]);
        $otherType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 10,
            'price_cents' => 10000,
        ]);
        $promoCode = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'STATE-CHECK',
        ]);
        $promoCode->admissionTypes()->attach($otherType);
        $url = route('public.festivals.admission.promo-code', [$account->slug, $edition->slug]);
        $payload = [
            'promo_code' => 'STATE-CHECK',
            'items' => [$selectedType->id => 1],
        ];

        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('promo_code');

        $promoCode->admissionTypes()->sync([$selectedType->id]);
        $promoCode->update(['is_active' => false]);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('promo_code');

        $promoCode->update([
            'is_active' => true,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('promo_code');

        $promoCode->update([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'currency' => strtoupper($account->default_currency) === 'USD' ? 'EUR' : 'USD',
        ]);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('promo_code');
    }

    public function test_pending_usage_reserves_identity_quota_failed_orders_release_it_and_refunds_remain_counted(): void
    {
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 20,
            'price_cents' => 10000,
        ]);
        $promoCode = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'ONCE',
            'total_usage_limit' => null,
            'per_identity_usage_limit' => 1,
        ]);
        $promoCode->admissionTypes()->attach($type);
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create([
            'email' => 'once@example.com',
            'email_normalized' => 'once@example.com',
            'phone' => '+380501112233',
            'phone_normalized' => '+380501112233',
        ]);
        $action = $this->createOrderAction($account);
        $input = $this->orderInput($type, $guest, 'ONCE');
        $first = $action->execute($edition, $input, $guest);

        try {
            $action->execute($edition, $input, $guest);
            $this->fail('The same Festival promotion identity was accepted twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promo_code', $exception->errors());
        }

        $first->update(['status' => FestivalTicketOrderStatus::Failed]);
        $second = $action->execute($edition, $input, $guest);
        $second->update(['status' => FestivalTicketOrderStatus::Refunded, 'expires_at' => null]);

        $this->expectException(ValidationException::class);
        $action->execute($edition, $input, $guest);
    }

    public function test_pending_usage_reserves_total_quota_and_expiry_releases_it(): void
    {
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 20,
            'price_cents' => 10000,
        ]);
        $promoCode = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'LAST-USE',
            'total_usage_limit' => 1,
            'per_identity_usage_limit' => null,
        ]);
        $promoCode->admissionTypes()->attach($type);
        $firstGuest = FestivalPortalUser::factory()->guest()->for($account)->create([
            'email' => 'first@example.com',
            'email_normalized' => 'first@example.com',
            'phone' => '+380501112231',
            'phone_normalized' => '+380501112231',
        ]);
        $secondGuest = FestivalPortalUser::factory()->guest()->for($account)->create([
            'email' => 'second@example.com',
            'email_normalized' => 'second@example.com',
            'phone' => '+380501112232',
            'phone_normalized' => '+380501112232',
        ]);
        $action = $this->createOrderAction($account);
        $first = $action->execute($edition, $this->orderInput($type, $firstGuest, 'LAST-USE'), $firstGuest);

        try {
            $action->execute($edition, $this->orderInput($type, $secondGuest, 'LAST-USE'), $secondGuest);
            $this->fail('The reserved last Festival promotion use was accepted twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promo_code', $exception->errors());
        }

        $first->update(['expires_at' => now()->subMinute()]);
        $second = $action->execute($edition, $this->orderInput($type, $secondGuest, 'LAST-USE'), $secondGuest);

        $this->assertSame($promoCode->id, $second->festival_promo_code_id);
    }

    public function test_public_entrance_promo_requires_email_and_a_full_discount_issues_without_a_provider(): void
    {
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 10,
            'price_cents' => 27500,
        ]);
        $promoCode = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'ENTRANCEFREE',
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 100,
        ]);
        $promoCode->admissionTypes()->attach($type);
        $url = route('public.festivals.entrance.store', [$account->slug, $edition->slug]);

        $this->from(route('public.festivals.entrance', [$account->slug, $edition->slug]))
            ->post($url, [
                'ticket_type_id' => $type->id,
                'guest_name' => 'Entrance Guest',
                'promo_code' => 'ENTRANCEFREE',
                'idempotency_key' => fake()->uuid(),
                'terms_accepted' => '1',
            ])
            ->assertSessionHasErrors('guest_email');

        $response = $this->post($url, [
            'ticket_type_id' => $type->id,
            'guest_name' => 'Entrance Guest',
            'guest_email' => 'entrance@example.com',
            'promo_code' => 'ENTRANCEFREE',
            'idempotency_key' => fake()->uuid(),
            'terms_accepted' => '1',
        ]);
        $order = FestivalTicketOrder::query()->where('promo_code', 'ENTRANCEFREE')->sole();

        $response->assertRedirect(route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]));
        $this->assertSame(FestivalTicketOrderStatus::Paid, $order->status);
        $this->assertSame(27500, $order->subtotal_cents);
        $this->assertSame(27500, $order->discount_cents);
        $this->assertSame(0, $order->amount_cents);
        $this->assertNull($order->provider);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_public_admission_full_discount_issues_without_opening_a_payment_provider(): void
    {
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 10,
            'price_cents' => 27500,
        ]);
        $promoCode = FestivalPromoCode::factory()->for($edition)->create([
            'account_id' => $account->id,
            'code' => 'CHECKOUTFREE',
            'discount_type' => PromoCodeDiscountType::Percent,
            'discount_value' => 100,
        ]);
        $promoCode->admissionTypes()->attach($type);

        $response = $this->post(route('public.festivals.admission.store', [$account->slug, $edition->slug]), [
            'buyer_name' => 'Free Checkout',
            'buyer_email' => 'free-checkout@example.com',
            'buyer_email_confirmation' => 'free-checkout@example.com',
            'buyer_phone' => '+380501112234',
            'promo_code' => 'checkoutfree',
            'items' => [$type->id => 1],
            'terms' => '1',
        ]);
        $order = FestivalTicketOrder::query()->where('promo_code', 'CHECKOUTFREE')->sole();

        $response->assertRedirect(route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]));
        $this->assertSame(FestivalTicketOrderStatus::Paid, $order->status);
        $this->assertNull($order->provider);
        $this->assertSame(27500, $order->subtotal_cents);
        $this->assertSame(27500, $order->discount_cents);
        $this->assertSame(0, $order->amount_cents);
        $this->assertSame(1, $order->tickets()->count());
    }

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $edition = FestivalEdition::factory()
            ->published()
            ->for(FestivalSeries::factory()->for($account))
            ->create([
                'account_id' => $account->id,
                'timezone' => 'Europe/Kyiv',
                'starts_at' => now()->addMonth(),
                'ends_at' => now()->addMonth()->addHours(6),
            ]);

        return [$account, $edition];
    }

    /** @param array<string, mixed> $overrides */
    private function promoPayload(FestivalAdmissionType $type, array $overrides = []): array
    {
        return [
            'name' => 'Festival launch',
            'code' => 'FEST10',
            'discount_type' => PromoCodeDiscountType::Percent->value,
            'discount_value' => 10,
            'starts_at' => now()->subHour()->timezone('Europe/Kyiv')->format('Y-m-d\TH:i'),
            'ends_at' => now()->addWeek()->timezone('Europe/Kyiv')->format('Y-m-d\TH:i'),
            'total_usage_limit' => null,
            'per_identity_usage_limit' => 1,
            'admission_type_ids' => [$type->id],
            'is_active' => 1,
            ...$overrides,
        ];
    }

    private function createOrderAction(Account $account): CreateFestivalTicketOrder
    {
        $setting = new IntegrationSetting(['provider' => 'monopay', 'is_enabled' => true]);
        $setting->account_id = $account->id;
        $gateways = Mockery::mock(PaymentGatewayRegistry::class);
        $gateways->shouldReceive('availableSettingsFor')
            ->with(Mockery::on(fn (Account $candidate): bool => $candidate->is($account)))
            ->andReturn(collect([$setting]));

        return new CreateFestivalTicketOrder(
            $gateways,
            app(ResolveFestivalGuest::class),
            app(FestivalTelegramIdentityLinker::class),
            app(FestivalPromoCodePricing::class),
        );
    }

    /** @return array<string, mixed> */
    private function orderInput(FestivalAdmissionType $type, FestivalPortalUser $guest, string $promoCode): array
    {
        return [
            'buyer_name' => $guest->displayName(),
            'buyer_email' => $guest->email,
            'buyer_phone' => $guest->phone,
            'provider' => 'monopay',
            'promo_code' => $promoCode,
            'items' => [['admission_type_id' => $type->id, 'quantity' => 1]],
        ];
    }
}
