<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\EventOrderStatus;
use App\Enums\McpToolInvocationStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerPurchase;
use App\Models\CustomerPurchaseRefund;
use App\Models\Event;
use App\Models\EventOrder;
use App\Models\EventOrderItem;
use App\Models\EventTicket;
use App\Models\EventTicketType;
use App\Models\McpToolInvocation;
use App\Models\StudioCashEntry;
use App\Models\StudioExpense;
use App\Support\AccountApiTokenIssuer;
use App\Support\Payments\StudioPaymentToolData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class LadnaStudioPaymentEventMcpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_payment_overview_is_account_scoped_currency_grouped_and_audit_redacted(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $otherAccount = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $customer = Customer::factory()->for($account)->create();
        $event = Event::factory()->for($account)->published()->create();

        $refundedPurchase = CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 10000,
                'currency' => 'UAH',
                'paid_at' => Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv'),
            ]);
        CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($refundedPurchase, 'customerPurchase')
            ->create([
                'method' => CustomerPurchaseRefund::MethodCashless,
                'amount_cents' => 2000,
                'currency' => 'UAH',
                'refunded_at' => Carbon::parse('2026-07-20 10:00:00', 'Europe/Kyiv'),
            ]);
        CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 3000,
                'currency' => 'USD',
                'paid_at' => Carbon::parse('2026-07-15 11:00:00', 'Europe/Kyiv'),
            ]);
        CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPending->value,
                'amount_cents' => 4000,
                'currency' => 'UAH',
                'created_at' => Carbon::parse('2026-07-19 11:00:00', 'Europe/Kyiv'),
            ]);
        CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 700,
                'currency' => 'UAH',
                'paid_at' => Carbon::parse('2026-06-30 21:00:00', 'UTC'),
            ]);
        CustomerPurchase::factory()
            ->for($account)
            ->for($customer)
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'amount_cents' => 800,
                'currency' => 'UAH',
                'paid_at' => Carbon::parse('2026-07-31 21:00:00', 'UTC'),
            ]);
        EventOrder::factory()
            ->for($account)
            ->for($event)
            ->create([
                'status' => EventOrderStatus::Paid->value,
                'amount_cents' => 5000,
                'currency' => 'UAH',
                'paid_at' => Carbon::parse('2026-07-16 10:00:00', 'Europe/Kyiv'),
            ]);
        StudioExpense::factory()->for($account)->create([
            'amount_cents' => 2000,
            'currency' => 'UAH',
            'occurred_at' => Carbon::parse('2026-07-17 10:00:00', 'Europe/Kyiv'),
        ]);
        StudioCashEntry::factory()->for($account)->create([
            'direction' => StudioCashEntry::DirectionOut,
            'purpose' => StudioCashEntry::PurposeOwnerWithdrawal,
            'amount_cents' => 1000,
            'currency' => 'UAH',
            'occurred_at' => Carbon::parse('2026-07-18 10:00:00', 'Europe/Kyiv'),
        ]);
        CustomerPurchase::factory()->for($otherAccount)->create([
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => 999999,
            'paid_at' => Carbon::parse('2026-07-15 10:00:00', 'Europe/Kyiv'),
        ]);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Payments MCP', [
            AccountApiTokenAbility::McpPaymentsRead,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-payment-overview', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.status', 'ok')
            ->assertJsonPath('result.structuredContent.totals.customer_income.0.amount_cents', 10700)
            ->assertJsonPath('result.structuredContent.totals.customer_refunds.0.amount_cents', 2000)
            ->assertJsonPath('result.structuredContent.totals.net_customer_income.0.amount_cents', 8700)
            ->assertJsonPath('result.structuredContent.totals.customer_income.1.currency', 'USD')
            ->assertJsonPath('result.structuredContent.totals.customer_income.1.amount_cents', 3000)
            ->assertJsonPath('result.structuredContent.totals.event_income.0.amount_cents', 5000)
            ->assertJsonPath('result.structuredContent.totals.income.0.amount_cents', 13700)
            ->assertJsonPath('result.structuredContent.totals.income.1.amount_cents', 3000)
            ->assertJsonPath('result.structuredContent.totals.operational_expenses.0.amount_cents', 2000)
            ->assertJsonPath('result.structuredContent.totals.owner_withdrawals.0.amount_cents', 1000)
            ->assertJsonPath('result.structuredContent.totals.remaining.0.amount_cents', 10700)
            ->assertJsonPath('result.structuredContent.totals.remaining.0.currency', 'UAH')
            ->assertJsonPath('result.structuredContent.totals.remaining.1.amount_cents', 3000)
            ->assertJsonPath('result.structuredContent.counts.customer_payments_by_status.payment_pending', 1);

        $invocation = McpToolInvocation::query()
            ->whereBelongsTo($account)
            ->where('tool_name', 'get-payment-overview')
            ->firstOrFail();

        $this->assertSame(['status' => 'ok'], $invocation->output);
        $this->assertStringNotContainsString('amount', json_encode($invocation->output));

        $apiToken->update(['is_active' => false]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-payment-overview'))
            ->assertUnauthorized()
            ->assertJsonPath('message', __('app.api_token_invalid'));
    }

    public function test_payment_search_masks_contacts_redacts_query_and_requires_payment_ability(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $otherAccount = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Sensitive Customer',
            'phone' => '+380671112233',
            'email' => 'sensitive@example.com',
        ]);
        $refundedPayment = CustomerPurchase::factory()->for($account)->for($customer)->create([
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'amount_cents' => 25000,
            'paid_at' => Carbon::parse('2026-07-20 14:00:00', 'Europe/Kyiv'),
        ]);
        CustomerPurchase::factory()->for($otherAccount)->create([
            'plan_name' => 'Sensitive Customer outside tenant',
            'status' => CustomerPurchaseStatus::PaymentPaid->value,
            'paid_at' => Carbon::parse('2026-07-20 14:00:00', 'Europe/Kyiv'),
        ]);
        $allowedToken = app(AccountApiTokenIssuer::class)->issue($account, 'Payments MCP', [
            AccountApiTokenAbility::McpPaymentsRead,
        ]);
        $deniedToken = app(AccountApiTokenIssuer::class)->issue($account, 'Read MCP', [
            AccountApiTokenAbility::McpRead,
        ]);

        $response = $this->withToken($allowedToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-payments', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'query' => 'Sensitive Customer',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.returned', 1)
            ->assertJsonPath('result.structuredContent.items.0.customer.name', 'Sensitive Customer')
            ->assertJsonPath('result.structuredContent.items.0.customer.phone_masked', '•••••••••2233')
            ->assertJsonPath('result.structuredContent.items.0.customer.email_masked', 's***@example.com');

        $response->assertDontSee('+380671112233')->assertDontSee('sensitive@example.com');

        CustomerPurchaseRefund::factory()
            ->for($account)
            ->for($refundedPayment, 'customerPurchase')
            ->create([
                'method' => CustomerPurchaseRefund::MethodCashless,
                'amount_cents' => 5000,
                'refunded_at' => Carbon::parse('2026-07-21 14:00:00', 'Europe/Kyiv'),
            ]);

        $refundResponse = $this->withToken($allowedToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-payments', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'query' => 'Sensitive Customer',
                'kind' => 'customer_refund',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.returned', 1)
            ->assertJsonPath('result.structuredContent.items.0.kind', 'customer_refund')
            ->assertJsonPath('result.structuredContent.items.0.status', CustomerPurchaseRefund::StatusRecorded)
            ->assertJsonPath('result.structuredContent.items.0.amount.amount_cents', 5000)
            ->assertJsonPath('result.structuredContent.items.0.customer.phone_masked', '•••••••••2233');

        $refundResponse->assertDontSee('+380671112233')->assertDontSee('sensitive@example.com');

        $invocation = McpToolInvocation::query()
            ->where('account_api_token_id', $allowedToken->id)
            ->where('tool_name', 'search-payments')
            ->firstOrFail();

        $this->assertSame(true, $invocation->input['query_applied']);
        $this->assertArrayNotHasKey('query', $invocation->input);
        $this->assertSame(['status' => 'found', 'returned' => 1, 'truncated' => false], $invocation->output);

        StudioExpense::factory()->for($account)->create([
            'reason' => 'Credentials secret-123 and bank account UA123456',
            'occurred_at' => Carbon::parse('2026-07-21 11:00:00', 'Europe/Kyiv'),
        ]);

        $expenseResponse = $this->withToken($allowedToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-payments', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'query' => 'Credentials secret-123',
                'kind' => 'operational_expense',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.returned', 1);

        $expenseResponse
            ->assertDontSee('Credentials secret-123')
            ->assertDontSee('UA123456');

        CustomerPurchase::factory()
            ->count(2)
            ->for($account)
            ->for($customer)
            ->create([
                'status' => CustomerPurchaseStatus::PaymentPaid->value,
                'paid_at' => Carbon::parse('2026-07-22 14:00:00', 'Europe/Kyiv'),
            ]);

        $this->withToken($allowedToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-payments', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-31',
                'kind' => 'customer_payment',
                'limit' => 2,
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.returned', 2)
            ->assertJsonPath('result.structuredContent.truncated', true);

        $this->withToken($deniedToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('search-payments'))
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', __('app.api_token_forbidden'));

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_api_token_id' => $deniedToken->id,
            'tool_name' => 'search-payments',
            'required_ability' => AccountApiTokenAbility::McpPaymentsRead->value,
            'status' => 'denied',
        ]);
    }

    public function test_event_overview_and_summary_return_inventory_revenue_and_refunds_without_buyers(): void
    {
        $this->travelTo(Carbon::parse('2026-07-29 12:00:00', 'Europe/Kyiv'));
        $account = Account::factory()->create(['timezone' => 'Europe/Kyiv']);
        $event = Event::factory()->for($account)->published()->create([
            'title' => 'Summer Intensive',
            'starts_at' => Carbon::parse('2026-08-10 10:00:00', 'Europe/Kyiv'),
            'ends_at' => Carbon::parse('2026-08-10 18:00:00', 'Europe/Kyiv'),
            'capacity' => 10,
            'currency' => 'UAH',
        ]);
        $ticketType = EventTicketType::factory()->for($account)->for($event)->create([
            'inventory' => 10,
            'price_cents' => 2500,
        ]);
        $paidOrder = EventOrder::factory()->for($account)->for($event)->create([
            'status' => EventOrderStatus::Paid->value,
            'amount_cents' => 5000,
            'paid_at' => now(),
            'buyer_name' => 'Hidden Buyer',
            'buyer_email' => 'hidden@example.com',
        ]);
        $paidItem = EventOrderItem::factory()
            ->state(['account_id' => $account->id])
            ->for($event)
            ->for($paidOrder, 'order')
            ->for($ticketType, 'ticketType')
            ->create(['quantity' => 2, 'total_cents' => 5000]);
        EventTicket::factory()
            ->count(2)
            ->state(['account_id' => $account->id])
            ->for($event)
            ->for($paidOrder, 'order')
            ->for($paidItem, 'orderItem')
            ->for($ticketType, 'ticketType')
            ->sequence(['is_checked_in' => true, 'checked_in_at' => now()], ['is_checked_in' => false])
            ->create();
        $refundOrder = EventOrder::factory()->for($account)->for($event)->create([
            'status' => EventOrderStatus::RefundRequired->value,
            'amount_cents' => 2000,
            'paid_at' => now(),
            'buyer_name' => 'Another Hidden Buyer',
        ]);
        EventOrderItem::factory()
            ->state(['account_id' => $account->id])
            ->for($event)
            ->for($refundOrder, 'order')
            ->for($ticketType, 'ticketType')
            ->create(['quantity' => 1, 'total_cents' => 2000]);
        $otherAccount = Account::factory()->create();
        $otherEvent = Event::factory()->for($otherAccount)->published()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Events MCP', [
            AccountApiTokenAbility::McpEventsRead,
        ]);

        $overview = $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-events-overview', [
                'date_from' => '2026-08-01',
                'date_to' => '2026-08-31',
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.returned', 1)
            ->assertJsonPath('result.structuredContent.events.0.event_id', $event->id)
            ->assertJsonPath('result.structuredContent.events.0.inventory.sold_or_held', 3)
            ->assertJsonPath('result.structuredContent.events.0.inventory.remaining_admission_inventory', 7)
            ->assertJsonPath('result.structuredContent.events.0.tickets.issued', 2)
            ->assertJsonPath('result.structuredContent.events.0.tickets.checked_in', 1)
            ->assertJsonPath('result.structuredContent.events.0.revenue.amount_cents', 7000)
            ->assertJsonPath('result.structuredContent.events.0.refund_required.orders', 1)
            ->assertJsonPath('result.structuredContent.events.0.refund_required.amount.amount_cents', 2000);

        $overview->assertDontSee('Hidden Buyer')->assertDontSee('hidden@example.com');

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-event-summary', [
                'event_id' => $event->id,
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.status', 'found')
            ->assertJsonPath('result.structuredContent.event.ticket_types.0.ticket_type_id', $ticketType->id)
            ->assertJsonPath('result.structuredContent.event.ticket_types.0.sold_or_held', 3)
            ->assertJsonPath('result.structuredContent.event.ticket_types.0.remaining', 7);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-event-summary', [
                'event_id' => $otherEvent->id,
            ]))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.status', 'not_found');
    }

    public function test_payment_and_event_tool_schemas_are_advertised_without_tenant_arguments(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Schema MCP', [
            AccountApiTokenAbility::McpRead,
        ]);

        $response = $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => [],
            ])
            ->assertOk();

        $tools = collect($response->json('result.tools'))->keyBy('name');

        foreach ([
            'get-payment-overview',
            'search-payments',
            'get-events-overview',
            'get-event-summary',
        ] as $toolName) {
            $this->assertTrue($tools->has($toolName));
            $this->assertArrayNotHasKey('account_id', $tools[$toolName]['inputSchema']['properties']);
        }

        $this->assertSame(50, data_get($tools, 'search-payments.inputSchema.properties.limit.maximum'));
        $this->assertContains('customer_refund', data_get($tools, 'search-payments.inputSchema.properties.kind.enum'));
        $this->assertContains('event_id', data_get($tools, 'get-event-summary.inputSchema.required'));
    }

    public function test_invalid_sensitive_tool_arguments_are_audited_separately_from_denials_and_failures(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Payments MCP', [
            AccountApiTokenAbility::McpPaymentsRead,
        ]);

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-payment-overview', [
                'date_from' => 'not-a-date',
            ]))
            ->assertOk()
            ->assertJsonPath('result.isError', true);

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_api_token_id' => $apiToken->id,
            'tool_name' => 'get-payment-overview',
            'status' => McpToolInvocationStatus::Invalid->value,
        ]);
    }

    public function test_sensitive_tool_failures_store_a_redacted_error(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Payments MCP', [
            AccountApiTokenAbility::McpPaymentsRead,
        ]);
        $paymentData = $this->mock(StudioPaymentToolData::class);
        $paymentData->shouldReceive('overview')
            ->once()
            ->andThrow(new RuntimeException('provider credential secret-value'));

        $this->withToken($apiToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->toolPayload('get-payment-overview'))
            ->assertInternalServerError();

        $invocation = McpToolInvocation::query()
            ->where('account_api_token_id', $apiToken->id)
            ->where('tool_name', 'get-payment-overview')
            ->firstOrFail();

        $this->assertSame(McpToolInvocationStatus::Failed, $invocation->status);
        $this->assertSame('Payment overview failed.', $invocation->error_message);
        $this->assertStringNotContainsString('secret-value', (string) $invocation->error_message);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolPayload(string $name, array $arguments = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ];
    }
}
