<?php

namespace Tests\Feature;

use App\Actions\IssueCustomerClassPass;
use App\Enums\AccountMode;
use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailScenario;
use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Listeners\TrackTransactionalMailSending;
use App\Listeners\TrackTransactionalMailSent;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\ClassPassPlan;
use App\Models\Customer;
use App\Models\EmailDelivery;
use App\Models\EmailScenarioSetting;
use App\Models\IntegrationSetting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Mail\EmailDeliveryRecorder;
use App\Support\Mail\TrackedMailTransport;
use App\Support\Mail\TransactionalMailDispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as LaravelSentMessage;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailDeliveryAuditTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dispatcher_creates_one_pending_delivery_before_queueing(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $account = Account::factory()->create([
            'default_language' => 'en',
            'timezone' => 'Europe/Kyiv',
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Audit Customer',
            'email' => 'audit@example.com',
            'default_language' => 'en',
        ]);
        $plan = ClassPassPlan::factory()->for($account)->create(['name' => 'AUDIT PASS']);

        app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        Mail::assertQueuedCount(1);
        $delivery = EmailDelivery::query()->sole();

        $this->assertSame($account->id, $delivery->account_id);
        $this->assertSame($customer->id, $delivery->customer_id);
        $this->assertNull($delivery->user_id);
        $this->assertSame(EmailScenario::CustomerClassPassIssued, $delivery->scenario);
        $this->assertSame(EmailDeliveryStatus::Pending, $delivery->status);
        $this->assertSame('audit@example.com', $delivery->recipient_email);
        $this->assertSame('Europe/Kyiv', $delivery->account_timezone);
        $this->assertSame('app.mail_subject_customer_class_pass_issued', $delivery->subject_key);
        $this->assertSame('mail.content.customer-class-pass-issued', $delivery->content_view);
        $this->assertSame('log', $delivery->configured_engine);
        $this->assertSame(0, $delivery->attempts);
        $this->assertSame('AUDIT PASS', $delivery->payload['pass_name']);
        $this->assertNotNull($delivery->queued_at);
    }

    public function test_disabled_scenario_creates_neither_delivery_nor_queued_mail(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        EmailScenarioSetting::factory()->create([
            'scenario' => EmailScenario::CustomerClassPassIssued,
            'is_enabled' => false,
        ]);
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create(['email' => 'disabled@example.com']);
        $plan = ClassPassPlan::factory()->for($account)->create();

        app(IssueCustomerClassPass::class)->execute($account, $customer, $plan);

        Mail::assertNothingQueued();
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_invalid_addresses_and_read_only_demo_accounts_are_suppressed(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $invalidAccount = Account::factory()->create();
        $invalidCustomer = Customer::factory()->for($invalidAccount)->create(['email' => 'not-an-email']);
        $invalidPlan = ClassPassPlan::factory()->for($invalidAccount)->create();
        $demoAccount = Account::factory()->create(['mode' => AccountMode::DemoReadonly]);
        $demoCustomer = Customer::factory()->for($demoAccount)->create(['email' => 'demo@example.com']);
        $demoPlan = ClassPassPlan::factory()->for($demoAccount)->create();

        app(IssueCustomerClassPass::class)->execute($invalidAccount, $invalidCustomer, $invalidPlan);
        app(IssueCustomerClassPass::class)->execute($demoAccount, $demoCustomer, $demoPlan);

        Mail::assertNothingQueued();
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_multiple_owner_recipients_get_independent_delivery_rows(): void
    {
        Mail::fake();
        $this->enableMailDelivery();
        $account = Account::factory()->create(['default_language' => 'en']);
        $firstOwner = User::factory()->create(['email' => 'first-owner@example.com']);
        $secondOwner = User::factory()->create(['email' => 'second-owner@example.com']);
        $account->addOwner($firstOwner);
        $account->addOwner($secondOwner);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Growth']);
        $subscription = AccountSubscription::factory()
            ->for($account)
            ->for($plan, 'plan')
            ->create();

        app(TransactionalMailDispatcher::class)->saasSubscriptionExpired($subscription);

        Mail::assertQueuedCount(2);
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertEqualsCanonicalizing(
            ['first-owner@example.com', 'second-owner@example.com'],
            EmailDelivery::query()->pluck('recipient_email')->map(
                fn (string $email): string => mb_strtolower($email),
            )->all(),
        );
    }

    public function test_disabling_after_enqueue_snapshots_and_skips_the_delivery(): void
    {
        $delivery = EmailDelivery::factory()->create([
            'scenario' => EmailScenario::BookingCreated,
            'status' => EmailDeliveryStatus::Pending,
        ]);
        EmailScenarioSetting::factory()->create([
            'scenario' => EmailScenario::BookingCreated,
            'is_enabled' => false,
        ]);
        $message = $this->message();

        $result = app(TrackTransactionalMailSending::class)->handle(new MessageSending($message, [
            'emailDeliveryId' => $delivery->id,
        ]));

        $delivery->refresh();
        $this->assertFalse($result);
        $this->assertSame(EmailDeliveryStatus::Skipped, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('Audit subject', $delivery->subject);
        $this->assertSame('<p>Audit body</p>', $delivery->html_body);
        $this->assertSame('Audit body', $delivery->text_body);
        $this->assertNotNull($delivery->skipped_at);
        $this->assertNull($delivery->actual_engine);
    }

    public function test_lifecycle_records_processing_transport_and_sent_acceptance(): void
    {
        $delivery = EmailDelivery::factory()->create([
            'scenario' => EmailScenario::BookingCreated,
            'status' => EmailDeliveryStatus::Pending,
        ]);
        $message = $this->message();

        $this->assertNull(app(TrackTransactionalMailSending::class)->handle(new MessageSending($message, [
            'emailDeliveryId' => $delivery->id,
        ])));

        $delivery->refresh();
        $this->assertSame(EmailDeliveryStatus::Processing, $delivery->status);
        $this->assertSame(1, $delivery->attempts);

        $message->getHeaders()->addTextHeader(TrackedMailTransport::DeliveryIdHeader, (string) $delivery->id);
        $sent = (new TrackedMailTransport(
            new ArrayTransport,
            app(EmailDeliveryRecorder::class),
            'log',
            true,
        ))->send($message, Envelope::create($message));

        $this->assertNotNull($sent);
        app(TrackTransactionalMailSent::class)->handle(new MessageSent(
            new LaravelSentMessage($sent),
            ['emailDeliveryId' => $delivery->id],
        ));

        $delivery->refresh();
        $this->assertSame(EmailDeliveryStatus::Sent, $delivery->status);
        $this->assertSame('log', $delivery->actual_engine);
        $this->assertTrue($delivery->fallback_used);
        $this->assertSame($sent->getMessageId(), $delivery->provider_message_id);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_mailable_failed_hook_records_a_sanitized_terminal_failure(): void
    {
        $delivery = EmailDelivery::factory()->create([
            'status' => EmailDeliveryStatus::Processing,
        ]);
        $mail = (new TransactionalMail(
            subjectKey: EmailScenario::BookingCreated->subjectKey(),
            contentView: EmailScenario::BookingCreated->contentView(),
            data: ['account_name' => 'Audit Studio'],
        ))->forEmailDelivery($delivery->id);

        $mail->failed(new RuntimeException('SMTP password=super-secret token=also-secret rejected'));

        $delivery->refresh();
        $this->assertSame(EmailDeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('password=[redacted]', $delivery->last_error);
        $this->assertStringContainsString('token=[redacted]', $delivery->last_error);
        $this->assertStringNotContainsString('super-secret', $delivery->last_error);
        $this->assertStringNotContainsString('also-secret', $delivery->last_error);
        $this->assertNotNull($delivery->failed_at);
    }

    public function test_delivery_history_survives_related_domain_record_deletion(): void
    {
        $account = Account::factory()->create();
        $customer = Customer::factory()->for($account)->create();
        $delivery = EmailDelivery::factory()
            ->for($account)
            ->for($customer)
            ->create([
                'recipient_email' => 'historical@example.com',
                'html_body' => '<p>Historical snapshot</p>',
            ]);

        $customer->delete();
        $account->delete();

        $delivery->refresh();
        $this->assertNull($delivery->account_id);
        $this->assertNull($delivery->customer_id);
        $this->assertSame('historical@example.com', $delivery->recipient_email);
        $this->assertSame('<p>Historical snapshot</p>', $delivery->html_body);
    }

    private function message(): Email
    {
        return (new Email)
            ->from(new Address('mail@ladna.test', 'Ladna'))
            ->to(new Address('customer@example.com', 'Customer'))
            ->subject('Audit subject')
            ->html('<p>Audit body</p>')
            ->text('Audit body');
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
                'mail_from_email' => 'audit@ladna.test',
                'mail_from_name' => 'Ladna Audit',
            ],
        ]);
    }
}
