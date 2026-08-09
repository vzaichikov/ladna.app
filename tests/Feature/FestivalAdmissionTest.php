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
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class FestivalAdmissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_inventory_holds_and_early_pricing_are_snapshotted_transactionally(): void
    {
        [$account, $edition] = $this->festival();
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
        $package = FestivalTariffPackage::factory()->create(['subscription_plan_id' => $plan->id, 'name' => 'Global cap '.str()->random(8)]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'festival_edition_id' => $edition->id,
            'max_tickets' => 2,
        ]);
        $firstType = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 2]);
        $secondType = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 2]);
        $create = $this->createOrderAction($account);
        $create->execute($edition, $this->orderInput($firstType, 2));

        $this->expectException(ValidationException::class);
        $create->execute($edition, $this->orderInput($secondType, 1, 'global-cap@example.com'));
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

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);

        return [$account, $edition];
    }
}
