<?php

namespace Tests\Feature;

use App\Actions\Payments\CompleteCustomerPurchase;
use App\Actions\Payments\CreateAdminCustomerPurchase;
use App\Enums\IntegrationProvider;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Payments\CustomerCartQuote;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerCartTrialSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    public function test_paid_ordinary_cart_is_issued_from_its_frozen_trial_flag_after_the_catalog_plan_becomes_trial(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $account = Account::factory()->internal()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $trainer = Trainer::factory()->for($account)->create();
        $scheduledClass = ScheduledClass::factory()->for($account)->for($location)->for($room)->for($trainer)->for($classType)->create();
        $customer = Customer::factory()->for($account)->create();
        ClassBooking::factory()->for($account)->for($customer)->for($scheduledClass)->create([
            'skip_class_pass_reservation' => true,
        ]);
        $plan = ClassPassPlan::factory()->for($account)->create([
            'price_cents' => 100000,
            'is_trial' => false,
        ]);
        $plan->classTypes()->attach($classType);
        IntegrationSetting::factory()->forAccountScope($account)->create([
            'provider' => IntegrationProvider::Liqpay,
            'is_enabled' => true,
            'credentials' => ['public_key' => 'test-public', 'private_key' => 'test-private'],
        ]);
        $items = [['class_pass_plan_id' => $plan->id, 'quantity' => 2]];
        $quote = app(CustomerCartQuote::class)->execute($account, $customer, $items);
        $purchase = app(CreateAdminCustomerPurchase::class)->execute($account, $customer, $owner, [
            'items' => $items,
            'location_id' => $location->id,
            'payment_method' => CustomerPurchase::PaymentMethodOnline,
            'provider' => IntegrationProvider::Liqpay->value,
            'quote_hash' => $quote['quote_hash'],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->assertNull($purchase->trial_eligibility_validated_at);
        $this->assertSame(0, $customer->customerClassPasses()->count());

        $plan->update(['is_trial' => true]);
        $completed = app(CompleteCustomerPurchase::class)->execute($purchase, new PaymentCallbackResult(
            orderId: $purchase->order_id,
            status: PaymentCallbackStatus::Paid,
            amountCents: 200000,
            currency: 'UAH',
            paidAt: now(),
        ));

        $this->assertTrue($completed->isPaid());
        $this->assertSame(2, $customer->customerClassPasses()->count());
        $this->assertTrue($plan->fresh()->is_trial);
        $this->assertFalse($completed->items->contains(fn ($item): bool => $item->is_trial));
        foreach ($completed->items as $item) {
            $this->assertNotNull($item->customerClassPass);
            $this->assertTrue($item->customerClassPass->is_paid);
            $this->assertSame(100000, $item->customerClassPass->paid_amount_cents);
        }
    }
}
