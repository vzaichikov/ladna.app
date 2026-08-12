<?php

namespace Tests\Feature;

use App\Enums\SmsSendingMode;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StudioPossibilitiesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_canonical_page_has_tabs_and_legacy_get_redirect_preserves_the_tab(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create(['enable_festivals' => false]);

        $this->actingAs($admin)
            ->get(route('platform.accounts.studio-possibilities.edit', [$account, 'tab' => 'festival-templates']))
            ->assertOk()
            ->assertViewIs('platform.accounts.studio-possibilities')
            ->assertSee(__('app.capabilities_and_sms'))
            ->assertSee(__('app.festival_landing_templates'))
            ->assertSee(__('app.festival_access_disabled'))
            ->assertSee('checked', false)
            ->assertSee('disabled', false);

        $this->actingAs($admin)
            ->get(route('platform.accounts.customer-auth.redirect', [$account, 'tab' => 'festival-templates']))
            ->assertRedirect(route('platform.accounts.studio-possibilities.edit', [$account, 'tab' => 'festival-templates']));
    }

    public function test_platform_template_grants_are_validated_logged_and_isolated_from_capability_settings(): void
    {
        $this->registerEditorialTemplate();
        $admin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create([
            'enable_festivals' => false,
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
            'enable_customer_notifications' => true,
        ]);
        $account->customerAuthSetting()->create([
            'allow_otp' => true,
            'sms_sending_mode' => SmsSendingMode::OwnGateway->value,
            'sms_provider' => 'smsclub',
        ]);

        $this->actingAs($admin)
            ->put(route('platform.accounts.studio-possibilities.festival-templates.update', $account), [
                'festival_landing_templates' => ['editorial'],
            ])
            ->assertRedirect(route('platform.accounts.studio-possibilities.edit', [$account, 'tab' => 'festival-templates']));

        $account->refresh();
        $this->assertSame(['editorial'], $account->allowed_festival_landing_templates);
        $this->assertFalse($account->enable_festivals);
        $this->assertTrue($account->allow_rtsp_cameras);
        $this->assertTrue($account->enable_people_counter);
        $this->assertTrue($account->enable_customer_notifications);
        $this->assertTrue($account->customerAuthSetting->allow_otp);
        $this->assertSame('smsclub', $account->customerAuthSetting->sms_provider);
        $this->assertDatabaseHas('account_activity_logs', [
            'account_id' => $account->id,
            'route_name' => 'platform.accounts.studio-possibilities.festival-templates.update',
        ]);

        $this->actingAs($admin)
            ->put(route('platform.accounts.studio-possibilities.update', $account), [
                'allow_otp' => '0',
                'allow_rtsp_cameras' => '0',
                'enable_people_counter' => '0',
                'enable_customer_notifications' => '0',
                'sms_sending_mode' => SmsSendingMode::Disabled->value,
                'sms_provider' => null,
            ])
            ->assertRedirect(route('platform.accounts.studio-possibilities.edit', $account));

        $this->assertSame(['editorial'], $account->fresh()->allowed_festival_landing_templates);
    }

    public function test_invalid_or_general_grant_keys_are_rejected_without_changing_saved_grants(): void
    {
        $this->registerEditorialTemplate();
        $admin = User::factory()->platformAdmin()->create();
        $account = Account::factory()->create([
            'allowed_festival_landing_templates' => ['editorial'],
        ]);

        foreach (['missing-template', 'general'] as $invalidKey) {
            $this->actingAs($admin)
                ->put(route('platform.accounts.studio-possibilities.festival-templates.update', $account), [
                    'festival_landing_templates' => [$invalidKey],
                ])
                ->assertSessionHasErrors('festival_landing_templates.0');
        }

        $this->assertSame(['editorial'], $account->fresh()->allowed_festival_landing_templates);
    }

    public function test_non_platform_user_cannot_view_or_update_studio_possibilities(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('platform.accounts.studio-possibilities.edit', $account))
            ->assertForbidden();

        $this->actingAs($owner)
            ->put(route('platform.accounts.studio-possibilities.festival-templates.update', $account), [])
            ->assertForbidden();
    }

    private function registerEditorialTemplate(): void
    {
        Config::set('festival_landing.templates.editorial', [
            'key' => 'editorial',
            'name_key' => 'app.festival_landing_palette_editorial_blush',
            'view' => 'festivals.public.templates.general',
            'thumbnail' => 'assets/festivals/landing-templates/general.webp',
        ]);
    }
}
