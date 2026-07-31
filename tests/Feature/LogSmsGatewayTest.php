<?php

namespace Tests\Feature;

use App\Support\CustomerAuth\LogSmsGateway;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LogSmsGatewayTest extends TestCase
{
    public function test_gateway_does_not_write_otp_message_content_to_logs(): void
    {
        Log::spy();

        $result = (new LogSmsGateway)->sendOtp('+380501112233', 'Your code is 123456');

        $this->assertTrue($result->sent);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Customer SMS requested.'
                    && $context === [
                        'phone' => '+380501112233',
                        'message_length' => 19,
                    ];
            });
    }
}
