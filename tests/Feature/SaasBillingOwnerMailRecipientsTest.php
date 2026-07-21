<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\AccountSubscriptionPaymentType;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionStatus;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPayment;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaasBillingOwnerMailRecipientsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_saas_payment_mail_is_queued_for_account_owners_only(): void
    {
        Mail::fake();
        $this->enableMailDelivery();

        $account = Account::factory()->create(['default_language' => 'en']);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $manager = User::factory()->create(['email' => 'manager@example.com']);
        $account->addOwner($owner);
        $account->users()->attach($manager, ['role' => AccountRole::Manager->value]);

        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna Studio']);
        $payment = AccountSubscriptionPayment::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create([
                'payment_type' => AccountSubscriptionPaymentType::ManualRenewal->value,
                'status' => AccountSubscriptionPaymentStatus::PaymentPaid->value,
                'amount_cents' => 90000,
                'currency' => 'UAH',
                'paid_at' => now(),
            ]);

        app(TransactionalMailDispatcher::class)->saasPaymentResolved($payment);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_saas_payment_paid'
            && $mail->hasTo('owner@example.com')
            && ! $mail->hasTo('manager@example.com'));
        Mail::assertNotQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->hasTo('manager@example.com'));
    }

    public function test_saas_expiry_mail_is_queued_for_account_owners_only(): void
    {
        Mail::fake();
        $this->enableMailDelivery();

        $account = Account::factory()->create(['default_language' => 'uk']);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $trainer = User::factory()->create(['email' => 'trainer@example.com']);
        $account->addOwner($owner);
        $account->users()->attach($admin, ['role' => AccountRole::Admin->value]);
        $account->users()->attach($trainer, ['role' => AccountRole::Trainer->value]);

        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna Studio']);
        $subscription = AccountSubscription::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create([
                'status' => SubscriptionStatus::Expired->value,
                'ends_at' => now()->subDay(),
            ]);

        app(TransactionalMailDispatcher::class)->saasSubscriptionExpired($subscription);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_saas_subscription_expired'
            && $mail->hasTo('owner@example.com')
            && ! $mail->hasTo('admin@example.com')
            && ! $mail->hasTo('trainer@example.com'));
        Mail::assertNotQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->hasTo('admin@example.com')
            || $mail->hasTo('trainer@example.com'));
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
                'mail_from_email' => 'billing@ladna.app',
                'mail_from_name' => 'Ladna Billing',
            ],
        ]);
    }
}
