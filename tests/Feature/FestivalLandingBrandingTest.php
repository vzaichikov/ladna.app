<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\User;
use App\Support\Festivals\FestivalLandingRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FestivalLandingBrandingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registry_contains_only_complete_trusted_entries_and_a_real_general_thumbnail(): void
    {
        $registry = app(FestivalLandingRegistry::class);
        $templates = $registry->templates();
        $palettes = $registry->palettes();

        $this->assertArrayHasKey('general', $templates);
        $this->assertSame('general', $templates['general']['key']);
        $this->assertTrue(view()->exists($templates['general']['view']));

        $thumbnailPath = public_path($templates['general']['thumbnail']);
        $this->assertFileExists($thumbnailPath);
        [$width, $height] = getimagesize($thumbnailPath);
        $this->assertSame($width * 9, $height * 16);

        $this->assertSame(
            ['general', 'editorial_blush', 'velvet_theatre', 'electric_stage', 'midnight_gold'],
            array_keys($palettes),
        );

        foreach ($palettes as $key => $palette) {
            $this->assertSame($key, $palette['key']);
            $this->assertSame([
                'page',
                'surface',
                'text',
                'muted_text',
                'primary',
                'primary_text',
                'accent',
                'accent_text',
                'border',
            ], array_keys($palette['tokens']));
        }

        $stylesheet = file_get_contents(resource_path('css/app.css'));
        $this->assertIsString($stylesheet);
        foreach ($palettes as $key => $palette) {
            $this->assertStringContainsString("data-festival-palette='{$key}'", $stylesheet);
            foreach ($palette['tokens'] as $color) {
                $this->assertStringContainsString(strtolower($color), $stylesheet);
            }
        }

        Config::set('festival_landing.templates.unsafe', [
            'key' => 'unsafe',
            'name_key' => 'app.festival_landing_template_general',
            'view' => '../../requested-view',
            'thumbnail' => '../requested-thumbnail.svg',
        ]);
        $this->assertArrayNotHasKey('unsafe', $registry->templates());
    }

    public function test_models_apply_general_defaults_and_account_grants_are_cast_to_an_array(): void
    {
        $account = Account::factory()->create();
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->for($series)->create(['account_id' => $account->id]);

        $this->assertSame([], $account->allowed_festival_landing_templates);
        $this->assertSame('general', $edition->landing_template);
        $this->assertSame('general', $edition->landing_palette);

        $account->update(['allowed_festival_landing_templates' => ['missing-template', 'general']]);
        $this->assertSame(
            ['general'],
            app(FestivalLandingRegistry::class)->availableTemplateKeys($account->fresh()),
        );
    }

    public function test_owner_can_update_branding_without_changing_edition_details(): void
    {
        $this->registerEditorialTemplate();
        [$account, $owner, $edition] = $this->ownerEdition([
            'allowed_festival_landing_templates' => ['editorial'],
        ]);
        $originalTitle = $edition->title;
        $originalSummary = $edition->summary;

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.branding.update', [$account, $edition]), [
                'landing_template' => 'editorial',
                'landing_palette' => 'editorial_blush',
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.edit', [$account, $edition, 'tab' => 'branding']));

        $edition->refresh();
        $this->assertSame('editorial', $edition->landing_template);
        $this->assertSame('editorial_blush', $edition->landing_palette);
        $this->assertSame($originalTitle, $edition->title);
        $this->assertSame($originalSummary, $edition->summary);
        $this->assertDatabaseHas('festival_activity_logs', [
            'festival_edition_id' => $edition->id,
            'actor_user_id' => $owner->id,
            'action' => 'edition.branding_updated',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.edit', [$account, $edition, 'tab' => 'branding']))
            ->assertOk()
            ->assertSee(__('app.festival_details'))
            ->assertSee(__('app.festival_branding'))
            ->assertSee(__('app.festival_landing_template_general'))
            ->assertSee(__('app.festival_landing_palette_editorial_blush'))
            ->assertSee('assets/festivals/landing-templates/general.webp', false);
    }

    public function test_revoked_template_falls_back_publicly_without_rewriting_and_restores_after_regrant(): void
    {
        $this->registerEditorialTemplate();
        [$account, , $edition] = $this->ownerEdition([
            'allowed_festival_landing_templates' => ['editorial'],
        ], [
            'status' => 'published',
            'registration_status' => 'open',
            'landing_template' => 'editorial',
            'landing_palette' => 'velvet_theatre',
            'rules_html' => '<p>Rules sentinel</p>',
            'published_at' => now(),
        ]);
        $publicUrl = route('public.festivals.show', [$account->slug, $edition->slug]);

        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('data-festival-template="editorial"', false)
            ->assertSee('data-festival-palette="velvet_theatre"', false)
            ->assertSee('Rules sentinel')
            ->assertSee(route('festival.login', $account->slug), false)
            ->assertSee(route('festival.judge.login', $account->slug), false)
            ->assertSee(route('public.festivals.admission.store', [$account->slug, $edition->slug]), false)
            ->assertSee(__('app.powered_by_ladna'))
            ->assertDontSee(route('api-docs.show'), false)
            ->assertDontSee(route('changelog.en'), false);

        $account->update(['allowed_festival_landing_templates' => []]);

        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('data-festival-template="general"', false);
        $this->assertSame('editorial', $edition->fresh()->landing_template);

        $account->update(['allowed_festival_landing_templates' => ['editorial']]);

        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('data-festival-template="editorial"', false);

        $edition->update(['landing_template' => 'removed-template', 'landing_palette' => 'removed-palette']);

        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('data-festival-template="general"', false)
            ->assertSee('data-festival-palette="general"', false);
    }

    public function test_palette_only_save_retains_an_unavailable_saved_template(): void
    {
        $this->registerEditorialTemplate();
        [$account, $owner, $edition] = $this->ownerEdition([], [
            'landing_template' => 'editorial',
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.edit', [$account, $edition, 'tab' => 'branding']))
            ->assertOk()
            ->assertSee(__('app.festival_landing_template_unavailable_title'))
            ->assertSee(__('app.effective'));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.branding.update', [$account, $edition]), [
                'landing_palette' => 'midnight_gold',
            ])
            ->assertSessionHasNoErrors();

        $edition->refresh();
        $this->assertSame('editorial', $edition->landing_template);
        $this->assertSame('midnight_gold', $edition->landing_palette);
    }

    public function test_branding_rejects_ungranted_templates_and_unauthorized_or_cross_tenant_updates(): void
    {
        $this->registerEditorialTemplate();
        [$account, $owner, $edition] = $this->ownerEdition();

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.branding.update', [$account, $edition]), [
                'landing_template' => 'editorial',
                'landing_palette' => 'general',
            ])
            ->assertSessionHasErrors('landing_template');

        $staff = User::factory()->create();
        $account->users()->attach($staff->id, ['role' => AccountRole::Trainer->value, 'permissions' => []]);
        $this->actingAs($staff)
            ->put(route('dashboard.accounts.festivals.branding.update', [$account, $edition]), [
                'landing_template' => 'general',
                'landing_palette' => 'general',
            ])
            ->assertForbidden();

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherOwner = User::factory()->create();
        $otherAccount->addOwner($otherOwner);

        $this->actingAs($otherOwner)
            ->put(route('dashboard.accounts.festivals.branding.update', [$otherAccount, $edition]), [
                'landing_template' => 'general',
                'landing_palette' => 'general',
            ])
            ->assertNotFound();

        $this->assertSame(0, FestivalActivityLog::query()->where('festival_edition_id', $edition->id)->count());
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

    /**
     * @param  array<string, mixed>  $accountAttributes
     * @param  array<string, mixed>  $editionAttributes
     * @return array{Account, User, FestivalEdition}
     */
    private function ownerEdition(array $accountAttributes = [], array $editionAttributes = []): array
    {
        $account = Account::factory()->create([
            'enable_festivals' => true,
            'default_language' => 'en',
            ...$accountAttributes,
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $series = FestivalSeries::factory()->for($account)->create(['name' => 'Festival series']);
        $edition = FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'summary' => 'Edition summary',
            ...$editionAttributes,
        ]);

        return [$account, $owner, $edition];
    }
}
