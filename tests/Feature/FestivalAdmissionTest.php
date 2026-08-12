<?php

namespace Tests\Feature;

use App\Actions\Festivals\CreateFestivalTicketOrder;
use App\Actions\Festivals\FestivalTicketIssuer;
use App\Actions\Festivals\FestivalTicketScanner;
use App\Enums\FestivalTicketOrderStatus;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
use App\Models\FestivalTicketOrder;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Festivals\FestivalPaymentService;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\Payments\PaymentGatewayRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class FestivalAdmissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_inventory_holds_and_early_pricing_preserve_transaction_facts(): void
    {
        [$account, $edition] = $this->festival();
        $account->update(['default_currency' => 'USD']);
        $edition->update(['currency' => 'UAH']);
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 2,
            'price_cents' => 30000,
            'early_bird_price_cents' => 20000,
            'early_bird_ends_at' => now()->addDay(),
            'early_bird_quota' => 2,
        ]);
        $create = $this->createOrderAction($account);
        $order = $create->execute($edition, $this->orderInput($type, 2));

        $this->assertSame(40000, $order->amount_cents);
        $this->assertSame('USD', $order->currency);
        $this->assertSame('early_bird', $order->items->first()->price_tier);
        $this->assertSame(0, $type->remainingQuantity());

        $this->expectException(ValidationException::class);
        $create->execute($edition, $this->orderInput($type, 1, 'second@example.com'));
    }

    public function test_ticket_tokens_are_encrypted_hashed_tenant_bound_and_duplicate_scans_are_audited(): void
    {
        Mail::fake();
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 5, 'price_cents' => 0]);
        $order = $this->createOrderAction($account)->execute($edition, $this->orderInput($type, 1));
        $order->update(['status' => FestivalTicketOrderStatus::Paid, 'paid_at' => now(), 'expires_at' => null]);
        app(FestivalTicketIssuer::class)->execute($order);
        $ticket = $order->tickets()->firstOrFail();
        $rawToken = $ticket->token_encrypted;
        $databaseToken = DB::table('festival_tickets')->where('id', $ticket->id)->value('token_encrypted');

        $this->assertNotSame($rawToken, $databaseToken);
        $this->assertSame(hash('sha256', $rawToken), $ticket->token_hash);

        $actor = User::factory()->create();
        $first = app(FestivalTicketScanner::class)->checkIn($edition, $rawToken, $actor, 'qr', '127.0.0.1');
        $duplicate = app(FestivalTicketScanner::class)->checkIn($edition, $rawToken, $actor, 'qr', '127.0.0.1');
        $checkout = app(FestivalTicketScanner::class)->checkOut($edition, $ticket->refresh(), $actor, 'Operator correction', '127.0.0.1');

        $this->assertSame('checked_in', $first['state']);
        $this->assertSame('already_checked_in', $duplicate['state']);
        $this->assertSame('checked_out', $checkout['state']);
        $this->assertDatabaseHas('festival_ticket_scans', ['festival_ticket_id' => $ticket->id, 'action' => 'check_in']);
        $this->assertDatabaseHas('festival_ticket_scans', ['festival_ticket_id' => $ticket->id, 'action' => 'check_out', 'reason' => 'Operator correction']);

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherEdition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($otherAccount))->create(['account_id' => $otherAccount->id]);
        $this->assertSame('invalid', app(FestivalTicketScanner::class)->checkIn($otherEdition, $rawToken, $actor, 'qr', null)['state']);
        $this->get(route('public.festival-orders.show', [$otherAccount->slug, $order->access_token_encrypted]))->assertNotFound();
    }

    public function test_late_paid_order_becomes_refund_required_without_issuing_tickets(): void
    {
        [$account, $edition] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 1]);
        $first = $this->createOrderAction($account)->execute($edition, $this->orderInput($type, 1));
        $first->forceFill(['status' => FestivalTicketOrderStatus::Paid, 'paid_at' => now(), 'expires_at' => null])->save();
        app(FestivalTicketIssuer::class)->execute($first);

        $late = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $account->id, 'provider' => 'monopay', 'amount_cents' => 30000, 'expires_at' => now()->subMinute()]);
        $late->items()->create(['account_id' => $account->id, 'festival_admission_type_id' => $type->id, 'admission_name' => $type->name, 'unit_price_cents' => 30000, 'quantity' => 1, 'total_cents' => 30000]);
        app(FestivalPaymentService::class)->completeOrder($late, new PaymentCallbackResult(orderId: $late->order_id, status: PaymentCallbackStatus::Paid, amountCents: 30000, currency: $late->currency));

        $this->assertSame(FestivalTicketOrderStatus::PaidRequiresRefund, $late->refresh()->status);
        $this->assertSame(0, $late->tickets()->count());
    }

    public function test_package_ticket_limit_is_global_across_admission_types_and_pending_holds(): void
    {
        [$account, $edition] = $this->festival();
        $plan = SubscriptionPlan::factory()->create(['currency' => 'UAH']);
        $package = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $plan->id,
            'name' => 'Global cap '.str()->random(8),
            'max_tickets' => 2,
        ]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'festival_edition_id' => $edition->id,
        ]);
        $firstType = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 2]);
        $secondType = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 2]);
        $create = $this->createOrderAction($account);
        $create->execute($edition, $this->orderInput($firstType, 2));

        $this->expectException(ValidationException::class);
        $create->execute($edition, $this->orderInput($secondType, 1, 'global-cap@example.com'));
    }

    public function test_finance_owner_can_create_update_and_delete_an_admission_type_with_edition_timezone_dates(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), $this->admissionPayload([
                'name' => 'Evening balcony',
                'sales_starts_at' => '2026-08-20T10:00',
                'sales_ends_at' => '2026-08-20T18:00',
            ]))
            ->assertRedirect(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types']));

        $type = $edition->admissionTypes()->where('name', 'Evening balcony')->firstOrFail();
        $this->assertSame(30000, $type->price_cents);
        $this->assertSame('2026-08-20 07:00:00', $type->sales_starts_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-20 15:00:00', $type->sales_ends_at?->format('Y-m-d H:i:s'));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.admission-types.update', [$account, $edition, $type]), $this->admissionPayload([
                'name' => 'Updated balcony',
                'inventory' => 80,
            ]))
            ->assertRedirect(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types']));

        $this->assertDatabaseHas('festival_admission_types', [
            'id' => $type->id,
            'name' => 'Updated balcony',
            'inventory' => 80,
        ]);

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.admission-types.destroy', [$account, $edition, $type]))
            ->assertRedirect(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types']));

        $this->assertDatabaseMissing('festival_admission_types', ['id' => $type->id]);
    }

    public function test_order_history_blocks_deletion_and_paid_history_locks_every_admission_type_field(): void
    {
        Queue::fake();
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'name' => 'Locked admission',
            'inventory' => 10,
        ]);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'status' => FestivalTicketOrderStatus::Paid->value,
            'amount_cents' => 30000,
            'paid_at' => now(),
            'expires_at' => null,
        ]);
        $order->items()->create([
            'account_id' => $account->id,
            'festival_admission_type_id' => $type->id,
            'admission_name' => $type->name,
            'unit_price_cents' => 30000,
            'quantity' => 1,
            'total_cents' => 30000,
        ]);

        $editUrl = route('dashboard.accounts.festivals.admission-types.edit', [$account, $edition, $type]);
        $this->actingAs($owner)
            ->from($editUrl)
            ->put(route('dashboard.accounts.festivals.admission-types.update', [$account, $edition, $type]), $this->admissionPayload([
                'name' => 'Attempted mutation',
                'inventory' => 20,
            ]))
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors('admission_type');

        $this->assertSame('Locked admission', $type->refresh()->name);
        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types']))
            ->delete(route('dashboard.accounts.festivals.admission-types.destroy', [$account, $edition, $type]))
            ->assertSessionHasErrors('admission_type');
        $this->assertDatabaseHas('festival_admission_types', ['id' => $type->id]);
    }

    public function test_pending_holds_protect_inventory_and_any_order_history_protects_deletion(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $type = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 10]);
        $order = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $account->id, 'expires_at' => now()->addHour()]);
        $order->items()->create([
            'account_id' => $account->id,
            'festival_admission_type_id' => $type->id,
            'admission_name' => $type->name,
            'unit_price_cents' => 30000,
            'quantity' => 4,
            'total_cents' => 120000,
        ]);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.admission-types.edit', [$account, $edition, $type]))
            ->put(route('dashboard.accounts.festivals.admission-types.update', [$account, $edition, $type]), $this->admissionPayload(['inventory' => 3, 'max_per_order' => 3]))
            ->assertSessionHasErrors('inventory');

        $this->actingAs($owner)
            ->delete(route('dashboard.accounts.festivals.admission-types.destroy', [$account, $edition, $type]))
            ->assertSessionHasErrors('admission_type');
        $this->assertSame(10, $type->refresh()->inventory);
    }

    public function test_admission_type_crud_enforces_package_inventory_and_price_constraints(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['currency' => 'UAH']);
        $package = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $plan->id,
            'name' => 'CRUD capacity '.str()->random(8),
            'max_tickets' => 100,
        ]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'festival_edition_id' => $edition->id,
        ]);
        FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 60,
        ]);
        $createUrl = route('dashboard.accounts.festivals.admission-types.create', [$account, $edition]);

        $this->actingAs($owner)
            ->from($createUrl)
            ->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), $this->admissionPayload([
                'inventory' => 50,
            ]))
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('inventory');

        $this->actingAs($owner)
            ->from($createUrl)
            ->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), $this->admissionPayload([
                'early_bird_price' => '300.00',
                'early_bird_ends_at' => '2026-08-20T10:00',
            ]))
            ->assertRedirect($createUrl)
            ->assertSessionHasErrors('early_bird_price');
    }

    public function test_admission_money_fields_use_major_units_and_render_decimal_edit_values(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'price_cents' => 50000,
            'early_bird_price_cents' => 12345,
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.admission-types.edit', [$account, $edition, $type]))
            ->assertOk()
            ->assertSee('name="price"', false)
            ->assertSee('value="500.00"', false)
            ->assertSee('name="early_bird_price"', false)
            ->assertSee('value="123.45"', false)
            ->assertDontSee('name="price_cents"', false)
            ->assertDontSee('name="early_bird_price_cents"', false)
            ->assertDontSee('minor units');

        foreach ([
            ['name' => 'Free admission', 'price' => '0', 'expected' => 0],
            ['name' => 'Whole admission', 'price' => '500', 'expected' => 50000],
            ['name' => 'Maximum admission', 'price' => '999999.99', 'expected' => 99999999],
        ] as $case) {
            $this->actingAs($owner)
                ->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), $this->admissionPayload([
                    'name' => $case['name'],
                    'price' => $case['price'],
                ]))
                ->assertSessionHasNoErrors();
            $this->assertSame($case['expected'], $edition->admissionTypes()->where('name', $case['name'])->value('price_cents'));
        }

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), $this->admissionPayload([
                'name' => 'Decimal early admission',
                'price' => '500.00',
                'early_bird_price' => '123.45',
                'early_bird_ends_at' => '2026-08-20T10:00',
            ]))
            ->assertSessionHasNoErrors();
        $decimalType = $edition->admissionTypes()->where('name', 'Decimal early admission')->firstOrFail();
        $this->assertSame(50000, $decimalType->price_cents);
        $this->assertSame(12345, $decimalType->early_bird_price_cents);

        foreach (['-0.01', '1.234', '1000000.00'] as $index => $invalidPrice) {
            $this->actingAs($owner)
                ->from(route('dashboard.accounts.festivals.admission-types.create', [$account, $edition]))
                ->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), $this->admissionPayload([
                    'name' => 'Invalid admission '.$index,
                    'price' => $invalidPrice,
                ]))
                ->assertSessionHasErrors('price');
        }
    }

    private function createOrderAction(Account $account): CreateFestivalTicketOrder
    {
        $setting = new IntegrationSetting(['provider' => 'monopay', 'is_enabled' => true]);
        $setting->account_id = $account->id;
        $gateways = Mockery::mock(PaymentGatewayRegistry::class);
        $gateways->shouldReceive('availableSettingsFor')
            ->with(Mockery::on(fn (Account $candidate): bool => $candidate->is($account)))
            ->andReturn(collect([$setting]));

        return new CreateFestivalTicketOrder($gateways);
    }

    /** @return array<string, mixed> */
    private function orderInput(FestivalAdmissionType $type, int $quantity, string $email = 'buyer@example.com'): array
    {
        return ['buyer_name' => 'Festival Guest', 'buyer_email' => $email, 'provider' => 'monopay', 'items' => [['admission_type_id' => $type->id, 'quantity' => $quantity]], 'terms' => true];
    }

    /** @param array<string, mixed> $overrides */
    private function admissionPayload(array $overrides = []): array
    {
        return [
            'name' => 'General admission',
            'description' => 'Festival access',
            'inventory' => 100,
            'price' => '300.00',
            'early_bird_price' => null,
            'early_bird_ends_at' => null,
            'early_bird_quota' => null,
            'sales_starts_at' => null,
            'sales_ends_at' => null,
            'max_per_order' => 10,
            'is_active' => 1,
            ...$overrides,
        ];
    }

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);

        return [$account, $edition];
    }
}
