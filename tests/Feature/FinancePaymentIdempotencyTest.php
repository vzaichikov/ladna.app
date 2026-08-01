<?php

namespace Tests\Feature;

use App\Actions\RecordManualClassBookingPayment;
use App\Actions\RecordManualCustomerClassPassPayment;
use App\Actions\RecordStudioCashEntry;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\StudioCashEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancePaymentIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_class_pass_cash_payment_replay_is_exact_and_changed_payload_is_rejected(): void
    {
        [$owner, $account, $firstLocation, $secondLocation, $customerClassPass] = $this->classPassContext();
        $action = app(RecordManualCustomerClassPassPayment::class);
        $idempotencyKey = (string) Str::uuid();

        $payment = $action->execute(
            $account,
            $customerClassPass,
            $firstLocation,
            4000,
            user: $owner,
            idempotencyKey: $idempotencyKey,
        );
        $replayed = $action->execute(
            $account,
            $customerClassPass,
            $firstLocation,
            4000,
            user: $owner,
            idempotencyKey: $idempotencyKey,
        );

        $this->assertSame($payment->id, $replayed->id);
        $this->assertSame(4000, $customerClassPass->fresh()->paid_amount_cents);
        $this->assertSame(1, $account->customerPurchases()->count());
        $this->assertSame(1, $account->studioCashEntries()->count());

        foreach ([
            ['location' => $firstLocation, 'amount_cents' => 5000],
            ['location' => $secondLocation, 'amount_cents' => 4000],
        ] as $changedPayload) {
            try {
                $action->execute(
                    $account,
                    $customerClassPass,
                    $changedPayload['location'],
                    $changedPayload['amount_cents'],
                    user: $owner,
                    idempotencyKey: $idempotencyKey,
                );
                $this->fail('A class-pass payment idempotency key accepted changed data.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('idempotency_key', $exception->errors());
            }
        }

        $this->assertSame(4000, $customerClassPass->fresh()->paid_amount_cents);
        $this->assertSame(1, $account->customerPurchases()->count());
        $this->assertSame(1, $account->studioCashEntries()->count());
    }

    public function test_booking_cash_payment_replay_is_exact_and_key_cannot_move_to_another_booking(): void
    {
        [$owner, $account, , $firstBooking, $secondBooking] = $this->bookingContext();
        $action = app(RecordManualClassBookingPayment::class);
        $idempotencyKey = (string) Str::uuid();

        $payment = $action->execute($account, $firstBooking, 3500, $owner, $idempotencyKey);
        $replayed = $action->execute($account, $firstBooking, 3500, $owner, $idempotencyKey);

        $this->assertSame($payment->id, $replayed->id);
        $this->assertSame(1, $account->customerPurchases()->count());
        $this->assertSame(1, $account->studioCashEntries()->count());

        foreach ([
            ['booking' => $firstBooking, 'amount_cents' => 3600],
            ['booking' => $secondBooking, 'amount_cents' => 3500],
        ] as $changedPayload) {
            try {
                $action->execute(
                    $account,
                    $changedPayload['booking'],
                    $changedPayload['amount_cents'],
                    $owner,
                    $idempotencyKey,
                );
                $this->fail('A booking payment idempotency key accepted changed data.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('idempotency_key', $exception->errors());
            }
        }

        $this->assertSame(1, $account->customerPurchases()->count());
        $this->assertSame(1, $account->studioCashEntries()->count());
    }

    public function test_class_pass_payment_and_paid_state_roll_back_when_ledger_creation_fails(): void
    {
        [$owner, $account, $location, , $customerClassPass] = $this->classPassContext();
        $nextPurchaseId = $this->nextAutoIncrementId('customer_purchases');

        app(RecordStudioCashEntry::class)->execute(
            $account,
            $location,
            StudioCashEntry::DirectionIn,
            1,
            now(),
            $owner,
            'Reserve a conflicting purchase ledger key.',
            sourceKey: 'purchase:'.$nextPurchaseId.':cash-in',
        );

        try {
            app(RecordManualCustomerClassPassPayment::class)->execute(
                $account,
                $customerClassPass,
                $location,
                4000,
                user: $owner,
                idempotencyKey: (string) Str::uuid(),
            );
            $this->fail('A class-pass payment survived a failed ledger write.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source_key', $exception->errors());
        }

        $this->assertSame(0, $customerClassPass->fresh()->paid_amount_cents);
        $this->assertFalse($customerClassPass->fresh()->is_paid);
        $this->assertSame(0, $account->customerPurchases()->count());
        $this->assertSame(1, $account->studioCashEntries()->count());
    }

    /**
     * @return array{0: User, 1: Account, 2: Location, 3: Location, 4: CustomerClassPass}
     */
    private function classPassContext(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $firstLocation = Location::factory()->for($account)->create();
        $secondLocation = Location::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create([
            'price_cents' => 10000,
            'currency' => 'UAH',
        ]);
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'price_cents' => 10000,
                'paid_amount_cents' => 0,
                'is_paid' => false,
                'currency' => 'UAH',
            ]);

        return [$owner, $account, $firstLocation, $secondLocation, $customerClassPass];
    }

    /**
     * @return array{0: User, 1: Account, 2: Location, 3: ClassBooking, 4: ClassBooking}
     */
    private function bookingContext(): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_currency' => 'UAH']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::RoomRental->value,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create();
        $firstCustomer = Customer::factory()->for($account)->create();
        $secondCustomer = Customer::factory()->for($account)->create();
        $firstBooking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($firstCustomer)
            ->create(['skip_class_pass_reservation' => true]);
        $secondBooking = ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($secondCustomer)
            ->create(['skip_class_pass_reservation' => true]);

        return [$owner, $account, $location, $firstBooking, $secondBooking];
    }

    private function nextAutoIncrementId(string $table): int
    {
        $result = DB::selectOne(
            'SELECT AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return (int) $result->next_id;
    }
}
