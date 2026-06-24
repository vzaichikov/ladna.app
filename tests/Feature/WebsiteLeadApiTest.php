<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\WebsiteLead;
use App\Support\AccountApiTokenIssuer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WebsiteLeadApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_website_lead_api_creates_lead_for_token_account(): void
    {
        $account = Account::factory()->create(['country_code' => 'UA']);
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
            ->assertJsonPath('data.source_page', 'https://studio.example.com/trial');

        $this->assertDatabaseHas('website_leads', [
            'account_id' => $account->id,
            'phone' => '+380671112233',
            'name' => 'Олена Коваль',
        ]);
        $this->assertNotNull($apiToken->refresh()->last_used_at);
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
