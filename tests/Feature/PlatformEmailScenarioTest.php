<?php

namespace Tests\Feature;

use App\Enums\EmailScenario;
use App\Enums\EmailScenarioGroup;
use App\Models\EmailScenarioSetting;
use App\Models\User;
use App\Support\Mail\EmailScenarioSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlatformEmailScenarioTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_view_grouped_scenarios_and_bilingual_previews(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();

        $response = $this->actingAs($platformAdmin)
            ->get(route('platform.email-scenarios.index'))
            ->assertOk()
            ->assertSee('data-platform-settings-tabs', false)
            ->assertSee('role="tablist"', false)
            ->assertSee('data-active-tab="'.EmailScenarioGroup::CustomerBookings->value.'"', false)
            ->assertSeeInOrder(array_map(
                fn (EmailScenarioGroup $group): string => __($group->labelKey()),
                EmailScenarioGroup::cases(),
            ));

        foreach (EmailScenarioGroup::cases() as $group) {
            $response
                ->assertSee('data-platform-settings-tab="'.$group->value.'"', false)
                ->assertSee('data-platform-settings-panel="'.$group->value.'"', false);
        }

        foreach (EmailScenario::cases() as $scenario) {
            $response
                ->assertSee('name="scenarios['.$scenario->value.']"', false)
                ->assertSee(route('platform.email-scenarios.preview', [$scenario, 'en']), false)
                ->assertSee(route('platform.email-scenarios.preview', [$scenario, 'uk']), false);
        }
    }

    public function test_requested_group_tab_is_active_and_preserved_after_save(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $activeGroup = EmailScenarioGroup::SubscriptionLifecycle;
        $submitted = collect(EmailScenario::cases())
            ->mapWithKeys(fn (EmailScenario $scenario): array => [$scenario->value => true])
            ->all();

        $this->actingAs($platformAdmin)
            ->get(route('platform.email-scenarios.index', ['tab' => $activeGroup->value]))
            ->assertOk()
            ->assertSee('data-active-tab="'.$activeGroup->value.'"', false)
            ->assertSeeInOrder([
                'id="email-scenarios-tab-'.$activeGroup->value.'"',
                'data-platform-settings-tab="'.$activeGroup->value.'"',
                'aria-controls="email-scenarios-panel-'.$activeGroup->value.'"',
                'aria-selected="true"',
            ], false);

        $this->actingAs($platformAdmin)
            ->put(route('platform.email-scenarios.update'), [
                'scenario_tab' => $activeGroup->value,
                'scenarios' => $submitted,
            ])
            ->assertRedirect(route('platform.email-scenarios.index', ['tab' => $activeGroup->value]));
    }

    public function test_platform_admin_can_persist_global_scenario_overrides(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $submitted = collect(EmailScenario::cases())
            ->mapWithKeys(fn (EmailScenario $scenario): array => [$scenario->value => true])
            ->put(EmailScenario::BookingCreated->value, false)
            ->all();

        $this->assertTrue(app(EmailScenarioSettings::class)->isEnabled(EmailScenario::BookingCreated));

        $this->actingAs($platformAdmin)
            ->put(route('platform.email-scenarios.update'), ['scenarios' => $submitted])
            ->assertRedirect(route('platform.email-scenarios.index'));

        $this->assertDatabaseHas('email_scenario_settings', [
            'scenario' => EmailScenario::BookingCreated->value,
            'is_enabled' => false,
        ]);
        $this->assertFalse(app(EmailScenarioSettings::class)->isEnabled(EmailScenario::BookingCreated));

        $submitted[EmailScenario::BookingCreated->value] = true;
        $this->actingAs($platformAdmin)
            ->put(route('platform.email-scenarios.update'), ['scenarios' => $submitted])
            ->assertRedirect(route('platform.email-scenarios.index'));

        $this->assertTrue(app(EmailScenarioSettings::class)->isEnabled(EmailScenario::BookingCreated));
        $this->assertSame(count(EmailScenario::cases()), EmailScenarioSetting::query()->count());
    }

    public function test_preview_is_sandboxed_bilingual_and_platform_only(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        $owner = User::factory()->create();

        foreach (['en', 'uk'] as $locale) {
            $this->actingAs($platformAdmin)
                ->get(route('platform.email-scenarios.preview', [EmailScenario::BookingCreated, $locale]))
                ->assertOk()
                ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:")
                ->assertSee('Ladna Demo Studio', false);
        }

        $this->actingAs($platformAdmin)
            ->get(route('platform.email-scenarios.preview', [EmailScenario::BookingCreated, 'fr']))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('platform.email-scenarios.index'))
            ->assertForbidden();
        $this->actingAs($owner)
            ->put(route('platform.email-scenarios.update'), ['scenarios' => []])
            ->assertForbidden();
        $this->actingAs($owner)
            ->get(route('platform.email-scenarios.preview', [EmailScenario::BookingCreated, 'en']))
            ->assertForbidden();
    }
}
