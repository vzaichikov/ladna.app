<?php

namespace Tests\Feature;

use App\Enums\AccountApiTokenAbility;
use App\Models\Account;
use App\Models\AccountSubscriptionPayment;
use App\Models\McpToolInvocation;
use App\Models\User;
use App\Support\AccountApiTokenIssuer;
use App\Support\CustomerAuth\CustomerAuthAvailability;
use App\Support\DemoStudioFixture;
use App\Support\Mcp\McpAccountContext;
use App\Support\Mobile\MobileSessionIssuer;
use App\Support\Payments\PaymentCallbackResult;
use App\Support\Payments\PaymentCallbackStatus;
use App\Support\SaasBilling\ResolveAccountSubscriptionPayment;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReadOnlyDemoHttpTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_browse_demo_but_owner_and_platform_mutations_are_blocked(): void
    {
        [$account, $owner] = $this->demoAccountWithOwner();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(__('app.demo_readonly_title'));

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.show', $account))
            ->put(route('dashboard.accounts.update', $account), ['name' => 'Changed demo'])
            ->assertRedirect(route('dashboard.accounts.show', $account))
            ->assertSessionHasErrors('demo');

        $this->assertNotSame('Changed demo', $account->fresh()->name);

        $accountCount = Account::query()->count();

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.show', $account))
            ->post(route('dashboard.accounts.store'), [
                'name' => 'Escaped studio',
                'slug' => 'escaped-studio',
            ])
            ->assertRedirect(route('dashboard.accounts.show', $account))
            ->assertSessionHasErrors('demo');

        $this->assertSame($accountCount, Account::query()->count());

        $platformAdmin = User::factory()->platformAdmin()->create();

        $this->actingAs($platformAdmin)
            ->from(route('platform.accounts.show', $account))
            ->put(route('platform.accounts.update', $account), ['name' => 'Platform changed demo'])
            ->assertRedirect(route('platform.accounts.show', $account))
            ->assertSessionHasErrors('demo');

        $this->assertNotSame('Platform changed demo', $account->fresh()->name);
    }

    public function test_public_customer_and_account_token_mutations_return_read_only_response(): void
    {
        [$account] = $this->demoAccountWithOwner();

        $this->from(route('home'))
            ->post(route('public.booking.store', [$account->slug, 'fictional-location']))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors('demo');

        $this->from(route('customer.studio.login', $account->slug))
            ->post(route('customer.email.login', $account->slug), [
                'email' => 'anna@example.test',
                'password' => 'password',
            ])
            ->assertRedirect(route('customer.studio.login', $account->slug))
            ->assertSessionHasErrors('demo');

        $token = app(AccountApiTokenIssuer::class)->issue(
            $account,
            'Unexpected demo token',
            [AccountApiTokenAbility::WebsiteLeadsCreate],
        );

        $this->withToken($token->tokenValue())
            ->postJson(route('api.v1.website-leads.store'), [
                'phone' => '+380001000001',
                'name' => 'Анна',
            ])
            ->assertStatus(Response::HTTP_LOCKED)
            ->assertJsonPath('code', 'demo_readonly');

        $this->assertNull($token->fresh()->last_used_at);
    }

    public function test_mobile_reads_and_logout_work_while_business_mutations_are_locked(): void
    {
        [$account, $owner] = $this->demoAccountWithOwner();
        $session = app(MobileSessionIssuer::class)->issueForStaff($account, $owner, 'owner');
        $token = (string) $session->getAttribute('plain_token');
        $lastUsedAt = $session->last_used_at;

        $this->withToken($token)
            ->getJson(route('api.v1.mobile.me'))
            ->assertOk();

        $this->withToken($token)
            ->postJson(route('api.v1.mobile.device-tokens.store'), [
                'token' => 'demo-device-token',
                'provider' => 'fcm',
            ])
            ->assertStatus(Response::HTTP_LOCKED)
            ->assertJsonPath('code', 'demo_readonly');

        $this->assertTrue($session->fresh()->last_used_at->equalTo($lastUsedAt));

        $this->withToken($token)
            ->postJson(route('api.v1.mobile.logout'))
            ->assertOk();

        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_customer_auth_methods_are_unavailable_and_mcp_mutation_abilities_are_locked(): void
    {
        [$account] = $this->demoAccountWithOwner();
        $methods = app(CustomerAuthAvailability::class)->methodsFor($account);

        $this->assertFalse($methods->emailPassword);
        $this->assertFalse($methods->otp);
        $this->assertFalse($methods->google);

        $readToken = app(AccountApiTokenIssuer::class)->issue(
            $account,
            'Unexpected demo MCP read token',
            [AccountApiTokenAbility::McpRead],
        );
        $this->assertNull($readToken->last_used_at);

        $this->withToken($readToken->tokenValue())
            ->postJson('/mcp/ladna-studio', $this->mcpToolPayload('get-studio-profile'))
            ->assertOk()
            ->assertJsonPath('result.structuredContent.studio.name', 'Ladna Demo Studio');

        $this->assertNull($readToken->fresh()->last_used_at);
        $this->assertSame(0, McpToolInvocation::query()->whereBelongsTo($account)->count());

        $token = app(AccountApiTokenIssuer::class)->issue(
            $account,
            'Unexpected demo MCP token',
            [AccountApiTokenAbility::McpBookingsCreate],
        );
        $request = Request::create('/mcp/ladna-studio', 'POST');
        $request->attributes->set('account', $account);
        $request->attributes->set('accountApiToken', $token);
        $this->app->instance('request', $request);

        try {
            app(McpAccountContext::class)->ensureAbility(AccountApiTokenAbility::McpBookingsCreate);
            $this->fail('The demo MCP mutation ability was not blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(Response::HTTP_LOCKED, $exception->getStatusCode());
            $this->assertSame(__('app.demo_readonly_message'), $exception->getMessage());
        }
    }

    public function test_legacy_saas_callback_resolution_cannot_mutate_demo_payments(): void
    {
        [$account] = $this->demoAccountWithOwner();
        $payment = AccountSubscriptionPayment::factory()->create([
            'account_id' => $account->id,
            'provider' => 'monopay',
            'order_id' => 'UNEXPECTED-DEMO-PAYMENT',
        ]);
        $callback = new PaymentCallbackResult(
            orderId: $payment->order_id,
            status: PaymentCallbackStatus::Paid,
        );

        try {
            app(ResolveAccountSubscriptionPayment::class)->execute('monopay', $callback);
            $this->fail('The demo SaaS callback was not blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(Response::HTTP_LOCKED, $exception->getStatusCode());
        }

        $this->assertSame('payment_started', $payment->fresh()->status->value);
    }

    public function test_demo_login_is_prefilled_does_not_remember_and_supports_logout(): void
    {
        [, $owner] = $this->demoAccountWithOwner();

        $this->get(route('demo.login'))
            ->assertOk()
            ->assertSee('value="'.config('demo-studio.owner.email').'"', false)
            ->assertSee('value="'.config('demo-studio.owner.password').'"', false)
            ->assertSee('name="remember" type="hidden" value="0"', false)
            ->assertDontSee('name="remember" type="checkbox"', false);

        $this->post(route('login'), [
            'email' => config('demo-studio.owner.email'),
            'password' => config('demo-studio.owner.password'),
            'remember' => '0',
        ])->assertRedirect(route('dashboard.index', absolute: false));

        $this->assertAuthenticatedAs($owner);

        $this->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    /**
     * @return array{0: Account, 1: User}
     */
    private function demoAccountWithOwner(): array
    {
        $account = Account::factory()->demoReadonly()->create([
            'name' => 'Ladna Demo Studio',
            'slug' => DemoStudioFixture::AccountSlug,
        ]);
        $owner = User::factory()->create([
            'name' => config('demo-studio.owner.name'),
            'email' => config('demo-studio.owner.email'),
            'password' => config('demo-studio.owner.password'),
        ]);
        $account->addOwner($owner);

        return [$account, $owner];
    }

    /** @return array<string, mixed> */
    private function mcpToolPayload(string $name): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => [],
            ],
        ];
    }
}
