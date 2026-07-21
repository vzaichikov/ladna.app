<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionPlanType;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPriceVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('billing:publish-founders-pricing {--effective= : Effective ISO date, defaults to now} {--execute : Publish the private version} {--force : Allow execution in production}')]
#[Description('Publish the private 650 UAH + 550 UAH/location Ladna Founders price without enrolling accounts.')]
class PublishFoundersLadnaPricing extends Command
{
    private const PLAN_NAME = 'Ladna Founders';

    private const PLAN_SLUG = 'ladna-founders';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $effectiveAt = filled($this->option('effective'))
            ? Carbon::parse((string) $this->option('effective'))
            : now();
        $plan = SubscriptionPlan::query()
            ->where('slug', self::PLAN_SLUG)
            ->with(['priceVersions.tiers'])
            ->first();

        $this->table(['Product', 'Visibility', 'Trial', 'Annual discount', 'Tier 1', 'Tier 2+'], [[
            self::PLAN_NAME,
            'Private',
            '30 days',
            '15%',
            '650 UAH',
            '550 UAH/location',
        ]]);

        if (! $this->option('execute')) {
            $this->components->info('Dry run only. Re-run with --execute to publish the private founders tariff.');

            return self::SUCCESS;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force together with --execute in production after the rollout backup step.');

            return self::FAILURE;
        }

        if ($plan && ! $this->isExpectedPrivateProduct($plan)) {
            $this->components->error('The ladna-founders slug is already used by a different or public product.');

            return self::FAILURE;
        }

        $publishedPrice = $plan?->priceVersions
            ->first(fn (SubscriptionPriceVersion $priceVersion): bool => $priceVersion->published_at !== null);

        if ($publishedPrice && ! $this->hasExpectedPricing($publishedPrice)) {
            $this->components->error('Ladna Founders already has a different published price. Create a new immutable version through platform administration.');

            return self::FAILURE;
        }

        [$plan, $priceVersion, $created] = DB::transaction(function () use ($plan, $publishedPrice, $effectiveAt): array {
            $plan ??= SubscriptionPlan::query()->create([
                'name' => self::PLAN_NAME,
                'slug' => self::PLAN_SLUG,
                'description' => 'Private founders pricing assigned manually by Ladna platform administrators.',
                'price_cents' => 0,
                'currency' => 'UAH',
                'billing_interval' => 'monthly',
                'plan_type' => SubscriptionPlanType::Standard,
                'access_days' => 30,
                'public_signup_enabled' => false,
                'requires_recurring_payment' => true,
                'renewal_lead_days' => 2,
                'is_active' => true,
                'sort_order' => ((int) SubscriptionPlan::query()->max('sort_order')) + 10,
            ]);

            $plan->forceFill([
                'public_signup_enabled' => false,
                'requires_recurring_payment' => true,
                'is_active' => true,
            ])->save();

            if ($publishedPrice) {
                return [$plan, $publishedPrice, false];
            }

            $priceVersion = $plan->priceVersions()->create([
                'version' => ((int) $plan->priceVersions()->max('version')) + 1,
                'currency' => 'UAH',
                'trial_days' => 30,
                'annual_discount_percent' => 15,
            ]);
            $priceVersion->tiers()->createMany([
                ['starts_at_location' => 1, 'ends_at_location' => 1, 'unit_price_cents' => 65_000],
                ['starts_at_location' => 2, 'ends_at_location' => null, 'unit_price_cents' => 55_000],
            ]);

            return [$plan, $priceVersion->publish($effectiveAt), true];
        });

        $message = $created
            ? "Published private founders price version {$priceVersion->version}."
            : "Private founders price version {$priceVersion->version} already exists and was kept.";
        $this->components->info($message.' No account was enrolled or modified.');

        return self::SUCCESS;
    }

    private function isExpectedPrivateProduct(SubscriptionPlan $plan): bool
    {
        return $plan->name === self::PLAN_NAME
            && $plan->plan_type === SubscriptionPlanType::Standard
            && ! $plan->public_signup_enabled;
    }

    private function hasExpectedPricing(SubscriptionPriceVersion $priceVersion): bool
    {
        return $priceVersion->currency === 'UAH'
            && $priceVersion->trial_days === 30
            && $priceVersion->annual_discount_percent === 15
            && $priceVersion->tiers->map(fn ($tier): array => [
                $tier->starts_at_location,
                $tier->ends_at_location,
                $tier->unit_price_cents,
            ])->all() === [
                [1, 1, 65_000],
                [2, null, 55_000],
            ];
    }
}
