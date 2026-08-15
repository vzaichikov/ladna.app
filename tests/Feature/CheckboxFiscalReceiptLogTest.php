<?php

namespace Tests\Feature;

use App\Enums\FiscalReceiptStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\FiscalReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckboxFiscalReceiptLogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_owner_can_review_newest_checkbox_receipts_without_exposing_contacts(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['default_language' => 'en']);
        $account->addOwner($owner);
        $event = Event::factory()->for($account)->create();
        $newestOrder = EventOrder::factory()->for($account)->for($event)->create([
            'order_id' => 'EV-CHECKBOX-NEWEST',
            'provider' => 'monopay',
            'amount_cents' => 12500,
        ]);
        $oldestOrder = EventOrder::factory()->for($account)->for($event)->create([
            'order_id' => 'EV-CHECKBOX-OLDEST',
            'provider' => 'monopay',
        ]);

        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($oldestOrder, 'payment')
            ->failed()
            ->create(['created_at' => now()->subDays(2)]);

        foreach (range(1, 19) as $index) {
            $fillerOrder = EventOrder::factory()->for($account)->for($event)->create([
                'order_id' => 'EV-CHECKBOX-FILLER-'.$index,
                'provider' => 'monopay',
            ]);

            FiscalReceipt::factory()
                ->forAccountScope($account)
                ->for($fillerOrder, 'payment')
                ->fiscalized('FN-'.$index)
                ->create(['created_at' => now()->subHour()->addSeconds($index)]);
        }

        FiscalReceipt::factory()
            ->forAccountScope($account)
            ->for($newestOrder, 'payment')
            ->failed('Checkbox rejected private@example.com and +380 (50) 111-22-33')
            ->create([
                'created_at' => now(),
                'request_payload' => [
                    'id' => 'safe-request-id',
                    'total_sum' => 12500,
                    'goods' => [[
                        'good' => ['code' => 'event-ticket', 'name' => 'Event ticket', 'price' => 12500],
                        'quantity' => 1000,
                    ]],
                    'delivery' => [
                        'email' => 'private@example.com',
                        'phone' => '+380 (50) 111-22-33',
                        'private@example.com' => 'unexpected key',
                    ],
                ],
                'response_payload' => [
                    'status' => 'validation_error',
                    'message' => 'Invalid contact private@example.com',
                    'detail' => [[
                        'loc' => ['body', 'delivery', 'phone'],
                        'msg' => 'Invalid +380 (50) 111-22-33',
                        'type' => 'value_error',
                        'input' => '+380 (50) 111-22-33',
                    ]],
                ],
            ]);

        $response = $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.checkbox-logs.index', $account));

        $response
            ->assertOk()
            ->assertSee('EV-CHECKBOX-NEWEST')
            ->assertDontSee('EV-CHECKBOX-OLDEST')
            ->assertSee('safe-request-id')
            ->assertSee('body.delivery.phone')
            ->assertSee(__('app.sensitive_value_hidden'))
            ->assertDontSee('private@example.com')
            ->assertDontSee('+380 (50) 111-22-33')
            ->assertSee('page=2', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.checkbox-logs.index', [
                'account' => $account,
                'q' => 'EV-CHECKBOX-OLDEST',
                'status' => FiscalReceiptStatus::Failed->value,
            ]))
            ->assertOk()
            ->assertSee('EV-CHECKBOX-OLDEST')
            ->assertDontSee('EV-CHECKBOX-NEWEST');
    }

    public function test_checkbox_receipt_log_is_tenant_scoped_and_owner_only(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $otherAccount->addOwner($otherOwner);
        $event = Event::factory()->for($otherAccount)->create();
        $otherOrder = EventOrder::factory()->for($otherAccount)->for($event)->create([
            'order_id' => 'EV-OTHER-STUDIO',
        ]);

        FiscalReceipt::factory()
            ->forAccountScope($otherAccount)
            ->for($otherOrder, 'payment')
            ->failed()
            ->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.checkbox-logs.index', [
                'account' => $account,
                'q' => 'EV-OTHER-STUDIO',
            ]))
            ->assertOk()
            ->assertSee(__('app.checkbox_receipt_log_no_matches'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.integrations.checkbox-logs.index', $otherAccount))
            ->assertForbidden();
    }
}
