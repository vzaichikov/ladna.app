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

#[Signature('billing:publish-initial-pricing {--plan=ladna-studio : Existing product slug} {--effective= : Effective ISO date, defaults to now} {--execute : Publish the version} {--force : Allow execution in production}')]
#[Description('Publish the initial 900 UAH + 800 UAH/location Ladna price version without enrolling accounts.')]
class PublishInitialLadnaPricing extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $plan = SubscriptionPlan::query()->where('slug', (string) $this->option('plan'))->first();

        if (! $plan) {
            $this->components->error('The requested subscription product does not exist.');

            return self::FAILURE;
        }

        $hasPublishedPrice = $plan->priceVersions()->published()->exists();

        $effectiveAt = filled($this->option('effective'))
            ? Carbon::parse((string) $this->option('effective'))
            : now();

        $this->table(['Product', 'Trial', 'Annual discount', 'Tier 1', 'Tier 2+'], [[
            $plan->name,
            '30 days',
            '10%',
            '900 UAH',
            '800 UAH/location',
        ]]);

        if (! $this->option('execute')) {
            if ($hasPublishedPrice) {
                $this->components->warn('This product already has a published price version. No price will be added.');
            }

            $this->components->info('Dry run only. Re-run with --execute to publish.');

            return self::SUCCESS;
        }

        if (! config('ladna.saas_billing_v2_enabled')) {
            $this->components->error('Enable Ladna billing v2 before publishing public pricing.');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->components->error('Use --force together with --execute in production after the rollout backup step.');

            return self::FAILURE;
        }

        $plan->forceFill([
            'plan_type' => SubscriptionPlanType::Standard,
            'public_signup_enabled' => true,
            'requires_recurring_payment' => true,
            'is_active' => true,
        ])->save();

        if ($hasPublishedPrice) {
            $this->components->info('The existing published price was kept and the public recurring product settings were ensured. No account was enrolled or modified.');

            return self::SUCCESS;
        }

        $priceVersion = DB::transaction(function () use ($plan, $effectiveAt): SubscriptionPriceVersion {
            $priceVersion = $plan->priceVersions()->create([
                'version' => ((int) $plan->priceVersions()->max('version')) + 1,
                'currency' => 'UAH',
                'trial_days' => 30,
                'annual_discount_percent' => 10,
            ]);
            $priceVersion->tiers()->createMany([
                ['starts_at_location' => 1, 'ends_at_location' => 1, 'unit_price_cents' => 90_000],
                ['starts_at_location' => 2, 'ends_at_location' => null, 'unit_price_cents' => 80_000],
            ]);

            return $priceVersion->publish($effectiveAt);
        });

        $this->components->info("Published price version {$priceVersion->version}. No account was enrolled or modified.");

        return self::SUCCESS;
    }
}
