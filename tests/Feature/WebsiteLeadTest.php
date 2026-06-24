<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Models\WebsiteLead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class WebsiteLeadTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_and_filter_website_leads(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        WebsiteLead::factory()->for($account)->create([
            'name' => 'Олена Коваль',
            'phone' => '+380671112233',
            'status' => WebsiteLeadStatus::New->value,
        ]);
        WebsiteLead::factory()->for($account)->create([
            'name' => 'Марія Шевченко',
            'phone' => '+380501234567',
            'status' => WebsiteLeadStatus::Callback->value,
        ]);
        WebsiteLead::factory()->for($otherAccount)->create([
            'name' => 'Олена Інша',
            'phone' => '+380991112233',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.website-leads.index', [
                'account' => $account,
                'q' => '067',
                'status' => WebsiteLeadStatus::New->value,
            ]))
            ->assertOk()
            ->assertSee('Олена Коваль')
            ->assertSee('+380671112233')
            ->assertDontSee('Марія Шевченко')
            ->assertDontSee('Олена Інша');
    }

    public function test_owner_can_update_website_lead_status_and_notes(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $websiteLead = WebsiteLead::factory()->for($account)->create([
            'status' => WebsiteLeadStatus::New->value,
        ]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.website-leads.update', [$account, $websiteLead]), [
                'status' => WebsiteLeadStatus::Callback->value,
                'notes' => 'Call after 18:00',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', __('app.website_lead_updated'));

        $websiteLead->refresh();

        $this->assertSame(WebsiteLeadStatus::Callback, $websiteLead->status);
        $this->assertSame('Call after 18:00', $websiteLead->notes);
    }

    public function test_trainer_without_permission_cannot_view_website_leads(): void
    {
        $trainer = User::factory()->create();
        $account = Account::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($trainer, 'user')
            ->create(['role' => AccountRole::Trainer->value, 'permissions' => null]);

        $this->actingAs($trainer)
            ->get(route('dashboard.accounts.website-leads.index', $account))
            ->assertForbidden();
    }
}
