<?php

namespace Tests\Feature;

use App\Enums\EmailDeliveryStatus;
use App\Enums\EmailScenario;
use App\Models\Account;
use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformEmailDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_search_and_filter_email_deliveries(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create(['name' => 'Email Audit Studio']);
        EmailDelivery::factory()->for($account)->create([
            'recipient_name' => 'Anna Email',
            'recipient_email' => 'anna-email@example.com',
            'subject' => 'Booking confirmation',
            'scenario' => EmailScenario::BookingCreated,
            'status' => EmailDeliveryStatus::Sent,
            'actual_engine' => 'sendpulse_api',
            'provider_message_id' => 'provider-audit-id',
            'sent_at' => now(),
        ]);
        EmailDelivery::factory()->create([
            'recipient_name' => 'Hidden Email',
            'scenario' => EmailScenario::SaasPaymentFailed,
            'status' => EmailDeliveryStatus::Failed,
            'actual_engine' => 'log',
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.email-deliveries.index', [
                'search' => 'provider-audit-id',
                'status' => EmailDeliveryStatus::Sent->value,
                'scenario' => EmailScenario::BookingCreated->value,
                'engine' => 'sendpulse_api',
            ]))
            ->assertOk()
            ->assertSee('Email Audit Studio', false)
            ->assertSee('Anna Email', false)
            ->assertSee('Booking confirmation', false)
            ->assertSee('provider-audit-id', false)
            ->assertDontSee('Hidden Email', false);
    }

    public function test_email_delivery_page_paginates_and_uses_persisted_account_timezone(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        foreach (range(1, 26) as $number) {
            EmailDelivery::factory()->create([
                'recipient_name' => sprintf('Audit Recipient %02d', $number),
                'account_timezone' => 'Europe/Kyiv',
                'queued_at' => Carbon::parse('2026-07-09 06:00:00', 'UTC'),
                'created_at' => Carbon::parse('2026-07-09 06:00:00', 'UTC')->addSecond($number),
            ]);
        }

        $this->actingAs($platformAdmin)
            ->get(route('platform.email-deliveries.index'))
            ->assertOk()
            ->assertViewHas('deliveries', fn ($deliveries): bool => $deliveries instanceof LengthAwarePaginator
                && $deliveries->perPage() === 25
                && $deliveries->hasPages())
            ->assertSee('09.07.2026 09:00', false)
            ->assertSee('page=2', false);
    }

    public function test_stored_snapshot_is_sandboxed_and_platform_only(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();
        $delivery = EmailDelivery::factory()->create([
            'html_body' => '<html><body><h1>Stored Email Snapshot</h1></body></html>',
        ]);

        $this->actingAs($platformAdmin)
            ->get(route('platform.email-deliveries.preview', $delivery))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:")
            ->assertSee('Stored Email Snapshot', false);

        $this->actingAs($owner)
            ->get(route('platform.email-deliveries.index'))
            ->assertForbidden();
        $this->actingAs($owner)
            ->get(route('platform.email-deliveries.preview', $delivery))
            ->assertForbidden();
    }
}
