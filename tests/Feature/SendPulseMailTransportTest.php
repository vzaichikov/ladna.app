<?php

namespace Tests\Feature;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationProvider;
use App\Mail\TransactionalMail;
use App\Models\EmailDelivery;
use App\Models\IntegrationSetting;
use App\Support\Mail\MailDeliveryTransportResolver;
use App\Support\Mail\SendPulseApiTransport;
use App\Support\Mail\TrackedMailTransport;
use App\Support\SendPulse\SendPulseApiClient;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\TestCase;

class SendPulseMailTransportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_transport_maps_email_payload_and_preserves_sendpulse_message_id(): void
    {
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::response([
                'result' => true,
                'id' => 'sendpulse-message-id',
            ]),
        ]);

        $email = $this->email()
            ->cc(new Address('copy@example.com', 'Copy Recipient'))
            ->bcc(new Address('blind@example.com', 'Blind Recipient'))
            ->replyTo(new Address('reply@example.com', 'Reply Desk'))
            ->attach('attachment body', 'receipt.txt', 'text/plain');

        $sentMessage = $this->transport()->send($email);

        $this->assertNotNull($sentMessage);
        $this->assertSame('sendpulse-message-id', $sentMessage->getMessageId());

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data()['email'] ?? [];

            return $request->url() === 'https://api.sendpulse.com/smtp/emails'
                && $request->hasHeader('Authorization', 'Bearer central-api-key')
                && $payload['html'] === base64_encode('<p>Hello from Ladna</p>')
                && $payload['text'] === 'Hello from Ladna'
                && $payload['subject'] === 'Transactional message'
                && $payload['from'] === ['name' => 'Ladna', 'email' => 'mail@ladna.test']
                && $payload['to'] === [['name' => 'Customer', 'email' => 'customer@example.com']]
                && $payload['cc'] === [['name' => 'Copy Recipient', 'email' => 'copy@example.com']]
                && $payload['bcc'] === [['name' => 'Blind Recipient', 'email' => 'blind@example.com']]
                && $payload['reply_to'] === ['name' => 'Reply Desk', 'email' => 'reply@example.com']
                && $payload['attachments_binary']['receipt.txt'] === base64_encode('attachment body');
        });
        Http::assertSentCount(1);
    }

    public function test_transport_rejects_unsuccessful_sendpulse_result(): void
    {
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::response([
                'result' => false,
                'message' => 'Sender domain is not verified.',
            ]),
        ]);

        try {
            $this->transport()->send($this->email());
            $this->fail('Expected SendPulse to reject the message.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('Sender domain is not verified.', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_transport_rejects_inline_mime_attachments_that_sendpulse_cannot_represent(): void
    {
        Http::fake();
        $inlineQr = (new DataPart('qr image', 'ticket.png', 'image/png'))
            ->asInline()
            ->setContentId('ticket@ladna.test');
        $email = $this->email()
            ->html('<p><img src="cid:ticket@ladna.test" alt="QR"></p>')
            ->addPart($inlineQr);

        try {
            $this->transport()->send($email);
            $this->fail('Expected the unsupported inline attachment to be rejected.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('does not support inline MIME attachments', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_transport_does_not_retry_client_errors(): void
    {
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::response([
                'message' => 'Invalid sender.',
            ], 422),
        ]);

        try {
            $this->transport()->send($this->email());
            $this->fail('Expected SendPulse to reject the message.');
        } catch (TransportException $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        Http::assertSentCount(1);
    }

    public function test_transport_retries_server_errors(): void
    {
        Http::fakeSequence('https://api.sendpulse.com/smtp/emails')
            ->push(['message' => 'Temporary failure.'], 503)
            ->push(['result' => true, 'id' => 'retry-message-id']);

        $sentMessage = $this->transport()->send($this->email());

        $this->assertNotNull($sentMessage);
        $this->assertSame('retry-message-id', $sentMessage->getMessageId());
        Http::assertSentCount(2);
    }

    public function test_transport_exhausts_connection_retries_without_leaking_credentials(): void
    {
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::failedConnection('Connection failed with central-api-key.'),
        ]);

        try {
            $this->transport()->send($this->email());
            $this->fail('Expected the SendPulse connection to fail.');
        } catch (TransportException $exception) {
            $this->assertSame('Request to SendPulse API failed.', $exception->getMessage());
            $this->assertStringNotContainsString('central-api-key', $exception->getMessage());
        }

        Http::assertSentCount(2);
    }

    public function test_queued_mail_loads_current_encrypted_api_key_in_fresh_worker(): void
    {
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::response([
                'result' => true,
                'id' => 'queued-message-id',
            ]),
        ]);

        $setting = IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::MailDelivery->value,
            'category' => IntegrationCategory::Email->value,
            'is_enabled' => true,
            'credentials' => $this->mailDeliveryCredentials('queued-api-key-a'),
        ]);

        $mailable = (new TransactionalMail(
            subjectKey: 'app.mail_subject_booking_created',
            contentView: 'mail.content.booking-created',
            data: [
                'recipient_name' => 'Customer',
                'account_name' => 'Ladna Studio',
            ],
            subjectParameters: [
                'class' => 'Pole Beginner',
                'studio' => 'Ladna Studio',
            ],
        ))
            ->mailer('ladna_transactional')
            ->from('mail@ladna.test', 'Ladna')
            ->to('customer@example.com', 'Customer');

        $serializedJob = serialize(new SendQueuedMailable($mailable));

        $this->assertStringNotContainsString('queued-api-key-a', $serializedJob);

        $setting->forceFill([
            'credentials' => $this->mailDeliveryCredentials('queued-api-key-b'),
        ])->save();

        app(MailManager::class)->purge('ladna_transactional');

        $job = unserialize($serializedJob);
        $this->assertInstanceOf(SendQueuedMailable::class, $job);

        $job->handle(app(MailFactory::class));

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.sendpulse.com/smtp/emails'
                && $request->hasHeader('Authorization', 'Bearer queued-api-key-b')
                && ! $request->hasHeader('Authorization', 'Bearer queued-api-key-a');
        });
        Http::assertSentCount(1);
    }

    public function test_resolver_records_sendpulse_as_the_actual_transport(): void
    {
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::response([
                'result' => true,
                'id' => 'tracked-sendpulse-id',
            ]),
        ]);
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::MailDelivery->value,
            'category' => IntegrationCategory::Email->value,
            'is_enabled' => true,
            'credentials' => $this->mailDeliveryCredentials('tracked-api-key'),
        ]);
        $delivery = EmailDelivery::factory()->create();
        $email = $this->email();
        $email->getHeaders()->addTextHeader(TrackedMailTransport::DeliveryIdHeader, (string) $delivery->id);

        app(MailDeliveryTransportResolver::class)->resolve()->send($email);

        $delivery->refresh();
        $this->assertSame('sendpulse_api', $delivery->actual_engine);
        $this->assertFalse($delivery->fallback_used);
        $this->assertSame('tracked-sendpulse-id', $delivery->provider_message_id);
        Http::assertSentCount(1);
    }

    public function test_resolver_records_log_fallback_after_sendpulse_rejection(): void
    {
        Http::fake([
            'https://api.sendpulse.com/smtp/emails' => Http::response([
                'message' => 'Sender rejected.',
            ], 422),
        ]);
        IntegrationSetting::factory()->create([
            'provider' => IntegrationProvider::MailDelivery->value,
            'category' => IntegrationCategory::Email->value,
            'is_enabled' => true,
            'credentials' => $this->mailDeliveryCredentials('fallback-api-key'),
        ]);
        $delivery = EmailDelivery::factory()->create();
        $email = $this->email();
        $email->getHeaders()->addTextHeader(TrackedMailTransport::DeliveryIdHeader, (string) $delivery->id);

        app(MailDeliveryTransportResolver::class)->resolve()->send($email);

        $delivery->refresh();
        $this->assertSame('log', $delivery->actual_engine);
        $this->assertTrue($delivery->fallback_used);
        $this->assertNotNull($delivery->provider_message_id);
        $this->assertStringContainsString('Sender rejected.', $delivery->last_error);
        Http::assertSentCount(1);
    }

    private function transport(): SendPulseApiTransport
    {
        return new SendPulseApiTransport(new SendPulseApiClient('central-api-key'));
    }

    private function email(): Email
    {
        return (new Email)
            ->from(new Address('mail@ladna.test', 'Ladna'))
            ->to(new Address('customer@example.com', 'Customer'))
            ->subject('Transactional message')
            ->html('<p>Hello from Ladna</p>')
            ->text('Hello from Ladna');
    }

    /**
     * @return array<string, mixed>
     */
    private function mailDeliveryCredentials(string $apiKey): array
    {
        return [
            'engine' => 'sendpulse_api',
            'fallback_engine' => 'log',
            'mail_from_email' => 'mail@ladna.test',
            'mail_from_name' => 'Ladna',
            'sendpulse_api_key' => $apiKey,
        ];
    }
}
