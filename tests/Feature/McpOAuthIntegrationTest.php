<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ClassType;
use App\Models\Location;
use App\Models\McpOAuthConnection;
use App\Models\McpToolInvocation;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\TestCase;

class McpOAuthIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_consent_exchange_code_and_call_the_bound_studio(): void
    {
        $this->configurePassportKeys();
        $owner = User::factory()->create();
        $account = Account::factory()->create(['name' => 'OAuth Dance Studio', 'slug' => 'oauth-dance-studio']);
        $account->addOwner($owner);
        $redirectUri = 'https://chatgpt.com/aip/callback';
        $client = Client::factory()->asPublic()->create([
            'account_id' => $account->id,
            'name' => 'ChatGPT',
            'redirect_uris' => [$redirectUri],
        ]);
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $authorizationUrl = route('passport.authorizations.authorize').'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->id,
            'redirect_uri' => $redirectUri,
            'scope' => 'offline_access mcp:use',
            'state' => 'oauth-test-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
        ]);

        $this->actingAs($owner, 'web')
            ->get($authorizationUrl)
            ->assertOk()
            ->assertSee('OAuth Dance Studio')
            ->assertSee(__('app.mcp_authorize_connect'));

        $authToken = (string) session('authToken');
        $approval = $this->actingAs($owner, 'web')->post(route('passport.authorizations.approve'), [
            'client_id' => $client->id,
            'auth_token' => $authToken,
        ])->assertRedirect();
        parse_str((string) parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $redirectQuery);

        $tokenResponse = $this->postJson(route('passport.token'), [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => $redirectUri,
            'code' => $redirectQuery['code'],
            'code_verifier' => $verifier,
        ])->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $firstRefreshToken = $tokenResponse->json('refresh_token');
        $refreshResponse = $this->postJson(route('passport.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $firstRefreshToken,
        ])->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);

        $this->assertNotSame($firstRefreshToken, $refreshResponse->json('refresh_token'));
        $this->postJson(route('passport.token'), [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $firstRefreshToken,
        ])->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');

        $this->withToken($refreshResponse->json('access_token'))
            ->postJson(
                route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
                $this->mcpPayload('tools/call', ['name' => 'get-studio-profile', 'arguments' => []]),
            )->assertOk()
            ->assertJsonPath('result.structuredContent.studio.name', 'OAuth Dance Studio');

        $this->assertDatabaseHas('mcp_oauth_connections', [
            'account_id' => $account->id,
            'user_id' => $owner->id,
            'oauth_client_id' => $client->id,
            'client_name' => 'ChatGPT',
        ]);
    }

    public function test_studio_discovery_advertises_account_bound_oauth_endpoints(): void
    {
        $account = Account::factory()->create(['slug' => 'dance-room']);
        $resource = route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]);
        $issuer = url('/oauth/mcp/'.$account->slug);

        $this->getJson(route('mcp.oauth.protected-resource.nested', ['path' => 'mcp/ladna-studio/'.$account->slug]))
            ->assertOk()
            ->assertJsonPath('resource', $resource)
            ->assertJsonPath('resource_name', 'Ladna — '.$account->name)
            ->assertJsonPath('resource_documentation', route('mcp.connection-guide.show', $account))
            ->assertJsonPath('authorization_servers.0', $issuer)
            ->assertJsonPath('scopes_supported.0', 'mcp:use');

        $this->getJson(route('mcp.oauth.authorization-server.nested', ['path' => 'oauth/mcp/'.$account->slug]))
            ->assertOk()
            ->assertJsonPath('issuer', $issuer)
            ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
            ->assertJsonPath('token_endpoint', route('passport.token'))
            ->assertJsonPath('registration_endpoint', route('mcp.oauth.register', ['account' => $account->slug]))
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256')
            ->assertJsonPath('scopes_supported.0', 'mcp:use')
            ->assertJsonPath('scopes_supported.1', 'offline_access')
            ->assertJsonPath('grant_types_supported.1', 'refresh_token');
    }

    public function test_unauthenticated_studio_request_points_to_its_exact_discovery_document(): void
    {
        $account = Account::factory()->create(['slug' => 'dance-room']);
        $metadataUrl = route('mcp.oauth.protected-resource.nested', [
            'path' => 'mcp/ladna-studio/'.$account->slug,
        ]);

        $this->postJson(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
            $this->mcpPayload('tools/list'),
        )->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.$metadataUrl.'"');
    }

    public function test_legacy_service_key_challenge_points_to_a_valid_non_oauth_resource_document(): void
    {
        $metadataUrl = route('mcp.oauth.protected-resource.nested', ['path' => 'mcp/ladna-studio']);

        $this->postJson(
            route('mcp.ladna-studio'),
            $this->mcpPayload('tools/list'),
        )->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.$metadataUrl.'"');

        $this->getJson($metadataUrl)
            ->assertOk()
            ->assertJsonPath('resource', route('mcp.ladna-studio'))
            ->assertJsonPath('resource_documentation', route('api-docs.show', ['tab' => 'mcp']))
            ->assertJsonMissingPath('authorization_servers');
    }

    public function test_dynamic_registration_binds_a_public_client_to_one_studio(): void
    {
        $account = Account::factory()->create(['slug' => 'dance-room']);

        $response = $this->postJson(route('mcp.oauth.register', ['account' => $account->slug]), [
            'client_name' => 'ChatGPT',
            'redirect_uris' => ['https://chatgpt.com/aip/callback'],
        ])->assertCreated()
            ->assertJsonPath('scope', 'mcp:use offline_access')
            ->assertJsonPath('token_endpoint_auth_method', 'none');

        $client = Client::query()->findOrFail($response->json('client_id'));

        $this->assertSame($account->id, (int) $client->account_id);
        $this->assertNull($client->secret);
        $this->assertSame(['authorization_code', 'refresh_token'], $response->json('grant_types'));
    }

    public function test_dynamic_registration_accepts_native_loopback_callbacks_in_production(): void
    {
        $account = Account::factory()->create(['slug' => 'dance-room']);
        $originalEnvironment = app()->environment();
        $originalRedirectDomains = config('mcp.redirect_domains');

        app()->detectEnvironment(fn (): string => 'production');
        config()->set('mcp.redirect_domains', [
            'https://chatgpt.com',
            'https://claude.ai',
            'https://claude.com',
        ]);

        try {
            foreach ([
                'http://127.0.0.1:49152/callback/codex-authentication',
                'http://[::1]:49153/callback/codex-authentication',
            ] as $redirectUri) {
                $response = $this->postJson(route('mcp.oauth.register', ['account' => $account->slug]), [
                    'client_name' => 'Codex',
                    'redirect_uris' => [$redirectUri],
                ])->assertCreated();

                $client = Client::query()->findOrFail($response->json('client_id'));

                $this->assertSame($account->id, (int) $client->account_id);
                $this->assertSame([$redirectUri], $client->redirect_uris);
            }
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
            config()->set('mcp.redirect_domains', $originalRedirectDomains);
        }
    }

    public function test_dynamic_registration_rejects_unsafe_loopback_variants_in_production(): void
    {
        $account = Account::factory()->create(['slug' => 'dance-room']);
        $originalEnvironment = app()->environment();
        $originalRedirectDomains = config('mcp.redirect_domains');

        app()->detectEnvironment(fn (): string => 'production');
        config()->set('mcp.redirect_domains', [
            'https://chatgpt.com',
            'https://claude.ai',
            'https://claude.com',
        ]);

        try {
            foreach ([
                'http://127.0.0.1/callback/codex-authentication',
                'http://localhost:49152/callback/codex-authentication',
                'http://127.0.0.1.evil.example:49152/callback/codex-authentication',
                'https://127.0.0.1:49152/callback/codex-authentication',
            ] as $redirectUri) {
                $this->postJson(route('mcp.oauth.register', ['account' => $account->slug]), [
                    'client_name' => 'Unknown app',
                    'redirect_uris' => [$redirectUri],
                ])->assertBadRequest()
                    ->assertJsonPath('error', 'invalid_redirect_uri');
            }
        } finally {
            app()->detectEnvironment(fn (): string => $originalEnvironment);
            config()->set('mcp.redirect_domains', $originalRedirectDomains);
        }
    }

    public function test_dynamic_registration_rejects_untrusted_and_oversized_redirect_lists(): void
    {
        $account = Account::factory()->create(['slug' => 'dance-room']);

        $this->postJson(route('mcp.oauth.register', ['account' => $account->slug]), [
            'client_name' => 'Unknown app',
            'redirect_uris' => ['https://chatgpt.com.evil.example/callback'],
        ])->assertBadRequest()
            ->assertJsonPath('error', 'invalid_redirect_uri');

        $this->postJson(route('mcp.oauth.register', ['account' => $account->slug]), [
            'client_name' => 'Too many callbacks',
            'redirect_uris' => array_fill(0, 11, 'https://chatgpt.com/aip/callback'),
        ])->assertBadRequest();
    }

    public function test_authorization_rejects_unbound_clients_and_non_mcp_scopes(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($user);
        $redirectUri = 'https://chatgpt.com/aip/callback';
        $unboundClient = Client::factory()->asPublic()->create([
            'redirect_uris' => [$redirectUri],
        ]);
        $boundClient = Client::factory()->asPublic()->create([
            'account_id' => $account->id,
            'redirect_uris' => [$redirectUri],
        ]);
        $parameters = [
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => 'oauth-test-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ];

        $this->actingAs($user, 'web')
            ->get(route('passport.authorizations.authorize').'?'.http_build_query([
                ...$parameters,
                'client_id' => $unboundClient->id,
                'scope' => 'mcp:use',
            ]))
            ->assertForbidden();

        $this->actingAs($user, 'web')
            ->get(route('passport.authorizations.authorize').'?'.http_build_query([
                ...$parameters,
                'client_id' => $boundClient->id,
                'scope' => '',
            ]))
            ->assertForbidden();

        $this->actingAs($user, 'web')
            ->get(route('passport.authorizations.authorize').'?'.http_build_query([
                ...$parameters,
                'client_id' => $boundClient->id,
                'scope' => 'mcp:use unknown',
            ]))
            ->assertForbidden();

        $this->actingAs($user, 'web')
            ->get(route('passport.authorizations.authorize').'?'.http_build_query([
                ...$parameters,
                'client_id' => $boundClient->id,
                'scope' => 'mcp:use',
            ]))
            ->assertOk()
            ->assertSee(__('app.mcp_authorize_connect'));
    }

    public function test_oauth_token_is_limited_to_its_bound_studio_and_live_role_permissions(): void
    {
        $user = User::factory()->create();
        $firstAccount = Account::factory()->create(['name' => 'First Studio', 'slug' => 'first-studio']);
        $secondAccount = Account::factory()->create(['name' => 'Second Studio', 'slug' => 'second-studio']);

        foreach ([$firstAccount, $secondAccount] as $account) {
            AccountMembership::factory()->for($account)->for($user)->create([
                'role' => AccountRole::Trainer->value,
                'permissions' => null,
            ]);
        }

        $client = Client::factory()->asPublic()->create(['account_id' => $firstAccount->id]);
        $connection = McpOAuthConnection::factory()->create([
            'account_id' => $firstAccount->id,
            'user_id' => $user->id,
            'oauth_client_id' => $client->id,
            'client_name' => 'ChatGPT',
        ]);
        Passport::actingAs($user, ['mcp:use'], 'api', $client);

        $tools = $this->postJson(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $firstAccount->slug]),
            $this->mcpPayload('tools/list'),
        )->assertOk()->json('result.tools');
        $toolNames = collect($tools)->pluck('name')->all();

        $this->assertContains('get-studio-profile', $toolNames);
        $this->assertContains('get-class-counts-for-day', $toolNames);
        $this->assertContains('get-class-bookings-for-day', $toolNames);
        $this->assertNotContains('search-customers', $toolNames);
        $this->assertNotContains('get-financial-report', $toolNames);

        $this->postJson(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $firstAccount->slug]),
            $this->mcpPayload('tools/call', ['name' => 'get-studio-profile', 'arguments' => []]),
        )->assertOk()->assertJsonPath('result.structuredContent.studio.name', 'First Studio');

        $this->assertDatabaseHas('mcp_tool_invocations', [
            'account_id' => $firstAccount->id,
            'mcp_oauth_connection_id' => $connection->id,
            'actor_user_id' => $user->id,
            'actor_role' => AccountRole::Trainer->value,
            'credential_type' => 'oauth_user',
            'tool_name' => 'get-studio-profile',
        ]);

        $this->postJson(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $secondAccount->slug]),
            $this->mcpPayload('tools/list'),
        )->assertForbidden();
    }

    public function test_trainer_schedule_tools_include_only_primary_and_additional_classes(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['slug' => 'trainer-schedule', 'timezone' => 'Europe/Kyiv']);
        AccountMembership::factory()->for($account)->for($user)->create([
            'role' => AccountRole::Trainer->value,
            'permissions' => null,
        ]);
        $location = Location::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create();
        $trainer = Trainer::factory()->for($account)->create(['user_id' => $user->id]);
        $otherTrainer = Trainer::factory()->for($account)->create();
        $startsAt = Carbon::parse('2026-08-20 10:00:00', 'Europe/Kyiv')->utc();

        $primaryClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($classType)
            ->for($trainer)
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);
        $additionalClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($classType)
            ->for($otherTrainer, 'trainer')
            ->create([
                'starts_at' => $startsAt->copy()->addHours(2),
                'ends_at' => $startsAt->copy()->addHours(3),
            ]);
        $additionalClass->additionalTrainers()->attach($trainer, ['account_id' => $account->id]);
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($classType)
            ->for($otherTrainer, 'trainer')
            ->create([
                'starts_at' => $startsAt->copy()->addHours(4),
                'ends_at' => $startsAt->copy()->addHours(5),
            ]);

        $client = Client::factory()->asPublic()->create(['account_id' => $account->id]);
        McpOAuthConnection::factory()->create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'oauth_client_id' => $client->id,
        ]);
        Passport::actingAs($user, ['mcp:use'], 'api', $client);

        $this->postJson(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
            $this->mcpPayload('tools/call', [
                'name' => 'get-class-counts-for-day',
                'arguments' => ['date' => '2026-08-20'],
            ]),
        )->assertOk()
            ->assertJsonPath('result.structuredContent.total', 2);

        $classes = $this->postJson(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
            $this->mcpPayload('tools/call', [
                'name' => 'get-class-bookings-for-day',
                'arguments' => ['date' => '2026-08-20'],
            ]),
        )->assertOk()
            ->assertJsonPath('result.structuredContent.total_classes', 2)
            ->json('result.structuredContent.classes');

        $this->assertSame(
            [$primaryClass->id, $additionalClass->id],
            collect($classes)->pluck('scheduled_class_id')->all(),
        );
    }

    public function test_trainer_schedule_tools_return_no_classes_without_an_active_linked_trainer(): void
    {
        $account = Account::factory()->create(['slug' => 'unlinked-trainer-schedule', 'timezone' => 'Europe/Kyiv']);
        $location = Location::factory()->for($account)->create();
        $classType = ClassType::factory()->for($account)->create();
        $classTrainer = Trainer::factory()->for($account)->create();
        $startsAt = Carbon::parse('2026-08-20 10:00:00', 'Europe/Kyiv')->utc();
        ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($classType)
            ->for($classTrainer, 'trainer')
            ->create([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);

        foreach ([true, false] as $hasInactiveTrainer) {
            $user = User::factory()->create();
            AccountMembership::factory()->for($account)->for($user)->create([
                'role' => AccountRole::Trainer->value,
                'permissions' => null,
            ]);

            if ($hasInactiveTrainer) {
                Trainer::factory()->for($account)->create([
                    'user_id' => $user->id,
                    'is_active' => false,
                ]);
            }

            $client = Client::factory()->asPublic()->create(['account_id' => $account->id]);
            McpOAuthConnection::factory()->create([
                'account_id' => $account->id,
                'user_id' => $user->id,
                'oauth_client_id' => $client->id,
            ]);
            Passport::actingAs($user, ['mcp:use'], 'api', $client);

            $this->postJson(
                route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
                $this->mcpPayload('tools/call', [
                    'name' => 'get-class-counts-for-day',
                    'arguments' => ['date' => '2026-08-20'],
                ]),
            )->assertOk()
                ->assertJsonPath('result.structuredContent.total', 0);

            $this->postJson(
                route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
                $this->mcpPayload('tools/call', [
                    'name' => 'get-class-bookings-for-day',
                    'arguments' => ['date' => '2026-08-20'],
                ]),
            )->assertOk()
                ->assertJsonPath('result.structuredContent.total_classes', 0);
        }
    }

    public function test_event_only_staff_cannot_use_an_existing_connection(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create();
        AccountMembership::factory()->for($account)->for($user)->create([
            'role' => AccountRole::EventFestivalStaff->value,
            'permissions' => null,
        ]);
        $client = Client::factory()->asPublic()->create(['account_id' => $account->id]);
        McpOAuthConnection::factory()->create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'oauth_client_id' => $client->id,
        ]);
        Passport::actingAs($user, ['mcp:use'], 'api', $client);

        $this->postJson(
            route('mcp.ladna-studio.oauth', ['accountSlug' => $account->slug]),
            $this->mcpPayload('tools/list'),
        )->assertForbidden();

        $this->assertSame(0, McpToolInvocation::query()->whereBelongsTo($account)->count());
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function mcpPayload(string $method, array $params = []): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ];
    }

    private function configurePassportKeys(): void
    {
        $key = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);

        config()->set('passport.private_key', $privateKey);
        config()->set('passport.public_key', $details['key']);
    }
}
