<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Actions\Payments\CompleteCustomerPurchase;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\IntegrationSetting;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\CompleteAccountSubscriptionPayment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TransactionalMailTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manual_class_pass_issue_queues_customer_email(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $account = Account::factory()->create(['default_language' => 'en']);
        $customer = Customer::factory()->for($account)->create(['email' => 'customer@example.com', 'default_language' => 'en']);
        $plan = ClassPassPlan::factory()->for($account)->create(['name' => 'BASE']);

        app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_customer_class_pass_issued'
            && $mail->contentView === 'mail.content.customer-class-pass-issued'
            && $mail->hasTo('customer@example.com')
            && ($mail->from[0]['address'] ?? null) === 'studio@example.com'
            && ($mail->from[0]['name'] ?? null) === 'Ladna Mail');
    }

    public function test_paid_customer_purchase_queues_issued_pass_email_once(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $account = Account::factory()->create(['default_language' => 'en']);
        $customer = Customer::factory()->for($account)->create(['email' => 'buyer@example.com', 'default_language' => 'en']);
        $plan = ClassPassPlan::factory()->for($account)->create(['name' => 'START', 'price_cents' => 180000, 'currency' => 'UAH']);
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'order_id' => 'ORDER-PAID-1',
                'plan_name' => 'START',
                'plan_slug' => 'start',
                'amount_cents' => 180000,
                'currency' => 'UAH',
                'sessions_count' => 8,
            ]);

        app(CompleteCustomerPurchase::class)->execute($purchase, new PaymentCallbackResult(
            orderId: 'ORDER-PAID-1',
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 180000,
            currency: 'UAH',
        ));

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_customer_class_pass_issued'
            && $mail->hasTo('buyer@example.com'));
    }

    public function test_failed_customer_purchase_queues_failure_email(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $account = Account::factory()->create(['default_language' => 'en']);
        $customer = Customer::factory()->for($account)->create(['email' => 'failed@example.com', 'default_language' => 'en']);
        $plan = ClassPassPlan::factory()->for($account)->create(['name' => 'START', 'price_cents' => 180000, 'currency' => 'UAH']);
        $purchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'order_id' => 'ORDER-FAILED-1',
                'plan_name' => 'START',
                'plan_slug' => 'start',
                'amount_cents' => 180000,
                'currency' => 'UAH',
            ]);

        app(CompleteCustomerPurchase::class)->execute($purchase, new PaymentCallbackResult(
            orderId: 'ORDER-FAILED-1',
            status: PaymentCallbackStatus::Failed,
            gatewayStatus: 'failure',
            amountCents: 180000,
            currency: 'UAH',
            failureReason: 'declined',
        ));

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_customer_purchase_failed'
            && $mail->contentView === 'mail.content.customer-purchase-failed'
            && $mail->hasTo('failed@example.com'));
    }

    public function test_booking_create_queues_customer_confirmation_email(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_language' => 'en']);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create(['cancellation_cutoff_minutes' => null]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create(['title' => 'Pole Beginner']);
        $customer = Customer::factory()->for($account)->create(['email' => 'booking@example.com', 'default_language' => 'en']);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.scheduled-classes.bookings.store', [$account, $scheduledClass]), [
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('dashboard.accounts.scheduled-classes.index', $account));

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_booking_created'
            && $mail->contentView === 'mail.content.booking-created'
            && $mail->hasTo('booking@example.com'));
    }

    public function test_paid_saas_payment_queues_owner_email(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $account = Account::factory()->create(['default_language' => 'en']);
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Studio']);
        $payment = AccountSubscriptionPayment::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create([
                'payment_type' => AccountSubscriptionPaymentType::ManualRenewal->value,
                'order_id' => 'SAAS-PAID-1',
                'amount_cents' => 99000,
                'currency' => 'UAH',
            ]);

        app(CompleteAccountSubscriptionPayment::class)->execute($payment, new PaymentCallbackResult(
            orderId: 'SAAS-PAID-1',
            status: PaymentCallbackStatus::Paid,
            gatewayStatus: 'success',
            amountCents: 99000,
            currency: 'UAH',
        ));

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_saas_payment_paid'
            && $mail->contentView === 'mail.content.saas-payment-paid'
            && $mail->hasTo('owner@example.com'));
    }

    private function enableMailDelivery(): void
    {
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::MailDelivery->value,
            'category' => IntegrationCategory::Email->value,
            'is_enabled' => true,
            'credentials' => [
                'engine' => 'log',
                'fallback_engine' => 'log',
                'mail_from_email' => 'studio@example.com',
                'mail_from_name' => 'Ladna Mail',
            ],
        ]);
    }
}
