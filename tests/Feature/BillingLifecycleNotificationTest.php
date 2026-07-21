<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Enums\SubscriptionBillingMode;
use App\Enums\SubscriptionStatus;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use App\Models\User;
use App\Support\SaasBilling\SendBillingLifecycleNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BillingLifecycleNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_lifecycle_notice_is_idempotent_and_delivered_to_owners_only(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $account = Account::factory()->create(['default_language' => 'en']);
        $owner = User::factory()->create(['email' => 'owner-notice@example.com']);
        $staff = User::factory()->create(['email' => 'staff-notice@example.com']);
        $account->addOwner($owner);
        $account->users()->attach($staff, ['role' => AccountRole::Manager->value]);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $trialEnd = now()->startOfMinute()->addDays(7);
        $subscription = AccountSubscription::factory()->for($account)->for($plan, 'plan')->create([
            'billing_mode' => SubscriptionBillingMode::LocationV2,
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $trialEnd,
            'ends_at' => $trialEnd,
        ]);
        $notifications = app(SendBillingLifecycleNotification::class);

        $notifications->execute($subscription, 'trial_ending_7', $trialEnd, ['date' => $trialEnd->format('d.m.Y')]);
        $notifications->execute($subscription, 'trial_ending_7', $trialEnd, ['date' => $trialEnd->format('d.m.Y')]);

        $this->assertDatabaseCount('account_subscription_notifications', 1);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_saas_trial_ending_7'
            && $mail->hasTo('owner-notice@example.com')
            && ! $mail->hasTo('staff-notice@example.com'));
        Mail::assertNotQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->hasTo('staff-notice@example.com'));
    }

    public function test_scheduled_price_change_is_announced_before_its_effective_date(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $account = Account::factory()->create(['default_language' => 'en']);
        $owner = User::factory()->create(['email' => 'price-owner@example.com']);
        $account->addOwner($owner);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Ladna']);
        $currentPrice = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published(now()->subDays(60))
            ->create(['version' => 1]);
        $replacement = SubscriptionPriceVersion::factory()->for($plan, 'plan')->create(['version' => 2]);
        $replacement->tiers()->create([
            'starts_at_location' => 1,
            'ends_at_location' => null,
            'unit_price_cents' => 100_000,
        ]);
        $replacement->schedule(now()->addDays(40));
        AccountSubscription::factory()->for($account)->for($plan, 'plan')->create([
            'subscription_price_version_id' => $currentPrice->id,
            'billing_mode' => SubscriptionBillingMode::LocationV2,
            'status' => SubscriptionStatus::Active,
            'ends_at' => now()->addMonth(),
        ]);

        $this->artisan('billing:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('account_subscription_notifications', [
            'notification_type' => 'price_change',
        ]);
        Mail::assertQueued(TransactionalMail::class, fn (TransactionalMail $mail): bool => $mail->subjectKey === 'app.mail_subject_saas_price_change'
            && $mail->hasTo('price-owner@example.com'));
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
