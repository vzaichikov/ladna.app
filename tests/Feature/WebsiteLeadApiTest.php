<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\WebsiteLead;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WebsiteLeadApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_website_lead_api_creates_lead_for_token_account(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 08:48:00', 'UTC'));

        $account = Account::factory()->create(['country_code' => 'UA', 'timezone' => 'Europe/Kyiv']);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Website form');

        $response = $this->withToken($apiToken->tokenValue())
            ->postJson('/api/v1/website-leads', [
                'phone' => '+380671112233',
                'name' => 'Олена Коваль',
                'source_page' => 'https://studio.example.com/trial',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.phone', '+380671112233')
            ->assertJsonPath('data.name', 'Олена Коваль')
            ->assertJsonPath('data.source_page', 'https://studio.example.com/trial')
            ->assertJsonPath('data.created_at', '2026-06-24T11:48:00+03:00');

        $this->assertDatabaseHas('website_leads', [
            'account_id' => $account->id,
            'phone' => '+380671112233',
            'name' => 'Олена Коваль',
        ]);
        $this->assertNotNull($apiToken->refresh()->last_used_at);

        Carbon::setTestNow();
    }

    public function test_website_lead_api_formats_created_at_in_bearer_account_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 08:48:00', 'UTC'));

        $account = Account::factory()->create(['timezone' => 'America/New_York']);
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Website form');

        $this->withToken($apiToken->tokenValue())
            ->postJson('/api/v1/website-leads', [
                'phone' => '+12125550123',
            ])
            ->assertCreated()
            ->assertJsonPath('data.created_at', '2026-06-24T04:48:00-04:00');

        Carbon::setTestNow();
    }

    public function test_website_lead_api_requires_active_bearer_token(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Website form');
        $apiToken->update(['is_active' => false]);

        $this->postJson('/api/v1/website-leads', [
            'phone' => '+380671112233',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', __('app.api_token_missing'));

        $this->withToken($apiToken->tokenValue())
            ->postJson('/api/v1/website-leads', [
                'phone' => '+380671112233',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('message', __('app.api_token_invalid'));

        $this->assertSame(0, WebsiteLead::whereBelongsTo($account)->count());
    }

    public function test_website_lead_api_requires_phone(): void
    {
        $account = Account::factory()->create();
        $apiToken = app(AccountApiTokenIssuer::class)->issue($account, 'Website form');

        $this->withToken($apiToken->tokenValue())
            ->postJson('/api/v1/website-leads', [
                'name' => 'Олена Коваль',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }
}
