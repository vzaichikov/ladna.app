<?php

namespace Database\Factories;

use App\Enums\FiscalReceiptStatus;
use App\Enums\IntegrationProvider;
use App\Enums\IntegrationScope;
use App\Models\Account;
use App\Models\FiscalReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FiscalReceipt>
 */
class FiscalReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => 0,
            'provider' => IntegrationProvider::Checkbox->value,
            'status' => FiscalReceiptStatus::Pending->value,
            'external_uuid' => (string) Str::uuid(),
            'attempts' => 0,
        ];
    }

    public function forAccountScope(Account $account): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_id' => $account->id,
            'scope_type' => IntegrationScope::Account->value,
            'scope_id' => $account->id,
        ]);
    }

    public function forPlatformScope(?Account $account = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_id' => $account?->id,
            'scope_type' => IntegrationScope::Platform->value,
            'scope_id' => 0,
        ]);
    }

    public function fiscalized(string $number = 'CHK-000001'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FiscalReceiptStatus::Fiscalized->value,
            'provider_status' => 'DONE',
            'fiscal_number' => $number,
            'sent_at' => now(),
            'fiscalized_at' => now(),
        ]);
    }

    public function failed(string $error = 'Fiscalization failed'): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FiscalReceiptStatus::Failed->value,
            'provider_status' => 'ERROR',
            'last_error' => $error,
            'sent_at' => now(),
            'failed_at' => now(),
        ]);
    }
}
