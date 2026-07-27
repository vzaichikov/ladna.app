<?php

namespace App\Support\Mail;

use App\Enums\EmailScenario;
use App\Models\EmailScenarioSetting;
use Illuminate\Support\Facades\DB;

class EmailScenarioSettings
{
    /**
     * @return array<string, bool>
     */
    public function enabledMap(): array
    {
        $overrides = EmailScenarioSetting::query()
            ->pluck('is_enabled', 'scenario')
            ->map(fn (mixed $enabled): bool => (bool) $enabled);

        return collect(EmailScenario::cases())
            ->mapWithKeys(fn (EmailScenario $scenario): array => [
                $scenario->value => $overrides->get($scenario->value, $scenario->defaultEnabled()),
            ])
            ->all();
    }

    public function isEnabled(EmailScenario $scenario): bool
    {
        $override = EmailScenarioSetting::query()
            ->where('scenario', $scenario->value)
            ->value('is_enabled');

        return $override === null ? $scenario->defaultEnabled() : (bool) $override;
    }

    /**
     * @param  array<string, bool>  $settings
     */
    public function save(array $settings): void
    {
        DB::transaction(function () use ($settings): void {
            foreach (EmailScenario::cases() as $scenario) {
                if (! array_key_exists($scenario->value, $settings)) {
                    continue;
                }

                EmailScenarioSetting::query()->updateOrCreate(
                    ['scenario' => $scenario->value],
                    ['is_enabled' => $settings[$scenario->value]],
                );
            }
        });
    }
}
