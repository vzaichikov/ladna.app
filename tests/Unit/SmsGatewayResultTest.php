<?php

namespace Tests\Unit;

use App\Support\CustomerAuth\SmsGatewayAcceptanceStatus;
use App\Support\CustomerAuth\SmsGatewayDeliveryStatus;
use App\Support\CustomerAuth\SmsGatewayResult;
use PHPUnit\Framework\TestCase;

class SmsGatewayResultTest extends TestCase
{
    public function test_sent_factory_remains_backwards_compatible_and_exposes_normalized_metadata(): void
    {
        $result = SmsGatewayResult::sent(
            providerMessageId: 'provider-id',
            providerSegmentCount: 2,
            wholesaleCostMinor: 264,
            wholesaleCostCurrency: 'UAH',
        );

        $this->assertTrue($result->sent);
        $this->assertSame('provider-id', $result->providerMessageId);
        $this->assertSame(SmsGatewayAcceptanceStatus::Accepted, $result->acceptanceStatus);
        $this->assertSame(SmsGatewayDeliveryStatus::Accepted, $result->deliveryStatus);
        $this->assertSame(2, $result->providerSegmentCount);
        $this->assertSame(264, $result->wholesaleCostMinor);
        $this->assertSame('UAH', $result->wholesaleCostCurrency);
    }

    public function test_old_constructor_signature_derives_acceptance_status(): void
    {
        $accepted = new SmsGatewayResult(true, providerMessageId: 'provider-id');
        $rejected = new SmsGatewayResult(false, 'Rejected');

        $this->assertSame(SmsGatewayAcceptanceStatus::Accepted, $accepted->acceptanceStatus);
        $this->assertSame(SmsGatewayAcceptanceStatus::Rejected, $rejected->acceptanceStatus);
    }

    public function test_unknown_result_is_not_reported_as_sent(): void
    {
        $result = SmsGatewayResult::unknown('Outcome unknown');

        $this->assertFalse($result->sent);
        $this->assertSame(SmsGatewayAcceptanceStatus::Unknown, $result->acceptanceStatus);
        $this->assertSame(SmsGatewayDeliveryStatus::Unknown, $result->deliveryStatus);
    }
}
