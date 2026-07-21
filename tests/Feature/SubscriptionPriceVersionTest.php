<?php

namespace Tests\Feature;

use App\Enums\SubscriptionPriceStatus;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceTier;
use App\Models\SubscriptionPriceVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class SubscriptionPriceVersionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_plan_resolves_the_latest_effective_published_price_version(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published(now()->subMonth())
            ->create(['version' => 1]);
        $current = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->published(now()->subDay())
            ->create(['version' => 2]);
        $scheduled = SubscriptionPriceVersion::factory()
            ->for($plan, 'plan')
            ->create(['version' => 3]);
        $this->createInitialTiers($scheduled);
        $scheduled->schedule(now()->addMonth());

        $resolved = $plan->currentPriceVersion();

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($current));
        $this->assertSame(SubscriptionPriceStatus::Published, $resolved->status);
        $this->assertCount(2, $resolved->tiers);
    }

    public function test_published_price_version_and_its_tiers_are_immutable(): void
    {
        $priceVersion = SubscriptionPriceVersion::factory()->published()->create();

        try {
            $priceVersion->update(['currency' => 'EUR']);
            $this->fail('Published price version update should have failed.');
        } catch (LogicException $exception) {
            $this->assertSame('Published price versions are immutable.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Tiers of a published price version are immutable.');

        $priceVersion->tiers()->firstOrFail()->update(['unit_price_cents' => 1]);
    }

    public function test_published_price_version_cannot_be_deleted_but_can_be_retired(): void
    {
        $priceVersion = SubscriptionPriceVersion::factory()->published()->create();
        $priceVersion->retire();

        $this->assertSame(SubscriptionPriceStatus::Retired, $priceVersion->status);
        $this->assertNotNull($priceVersion->retired_at);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Published price versions cannot be deleted.');

        $priceVersion->delete();
    }

    public function test_invalid_tier_ranges_cannot_be_published(): void
    {
        $priceVersion = SubscriptionPriceVersion::factory()->create();
        SubscriptionPriceTier::factory()->for($priceVersion, 'priceVersion')->create([
            'starts_at_location' => 1,
            'ends_at_location' => 1,
        ]);
        SubscriptionPriceTier::factory()->for($priceVersion, 'priceVersion')->create([
            'starts_at_location' => 3,
            'ends_at_location' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contiguous');

        $priceVersion->publish();
    }

    private function createInitialTiers(SubscriptionPriceVersion $priceVersion): void
    {
        SubscriptionPriceTier::factory()->for($priceVersion, 'priceVersion')->create([
            'starts_at_location' => 1,
            'ends_at_location' => 1,
            'unit_price_cents' => 90_000,
        ]);
        SubscriptionPriceTier::factory()->for($priceVersion, 'priceVersion')->create([
            'starts_at_location' => 2,
            'ends_at_location' => null,
            'unit_price_cents' => 80_000,
        ]);
    }
}
