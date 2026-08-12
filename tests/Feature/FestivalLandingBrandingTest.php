<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\FestivalActivityLog;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalContentSection;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalMedia;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalWorkflow;
use App\Models\FestivalWorkflowStep;
use App\Models\User;
use App\Support\Festivals\FestivalLandingRegistry;
use App\Support\MoneyFormatter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class FestivalLandingBrandingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registry_contains_only_complete_trusted_entries_and_real_template_thumbnails(): void
    {
        $registry = app(FestivalLandingRegistry::class);
        $templates = $registry->templates();
        $palettes = $registry->palettes();

        $this->assertSame(['general', 'velvet_night'], array_keys($templates));
        $this->assertArrayHasKey('general', $templates);
        $this->assertSame('general', $templates['general']['key']);

        foreach ($templates as $key => $template) {
            $this->assertSame($key, $template['key']);
            $this->assertTrue(view()->exists($template['view']));
            $this->assertNotSame($template['name_key'], __($template['name_key'], [], 'en'));
            $this->assertNotSame($template['name_key'], __($template['name_key'], [], 'uk'));

            $thumbnailPath = public_path($template['thumbnail']);
            $this->assertFileExists($thumbnailPath);
            [$width, $height] = getimagesize($thumbnailPath);
            $this->assertSame($width * 9, $height * 16);
        }

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
            ->assertSee('data-festival-template="general"', false)
            ->assertDontSee('data-velvet-scroll-top', false);
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

    public function test_velvet_night_renders_the_public_contract_and_uses_a_single_centered_hero_as_fallback(): void
    {
        [$account, , $edition] = $this->ownerEdition([
            'allowed_festival_landing_templates' => ['velvet_night'],
        ], [
            'status' => 'published',
            'registration_status' => 'open',
            'landing_template' => 'velvet_night',
            'landing_palette' => 'velvet_theatre',
            'rules_html' => '<p>Velvet rules sentinel</p>',
            'published_at' => now(),
        ]);
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://example.test/desktop-hero.jpg',
            'is_cover' => true,
        ]);

        $publicUrl = route('public.festivals.show', [$account->slug, $edition->slug]);
        $response = $this->get($publicUrl)
            ->assertOk()
            ->assertSee('data-festival-template="velvet_night"', false)
            ->assertSee('class="velvet-hero-image"', false)
            ->assertSee('data-velvet-scroll-top', false)
            ->assertSee('aria-label="'.__('app.festival_back_to_top').'"', false)
            ->assertDontSee('<source media="(max-width: 767px)"', false)
            ->assertSee($edition->title)
            ->assertSee($edition->summary)
            ->assertSee('Velvet rules sentinel')
            ->assertSee(__('app.festival_apply'))
            ->assertSee(__('app.buy_tickets'))
            ->assertSee(route('festival.login', $account->slug), false)
            ->assertSee(route('festival.judge.login', $account->slug), false)
            ->assertSee(route('public.festivals.admission.store', [$account->slug, $edition->slug]), false)
            ->assertDontSee(__('app.all_festivals'))
            ->assertSee(__('app.powered_by_ladna'));

        $this->assertSame(2, substr_count($response->getContent(), $edition->series->name));

        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://example.test/mobile-hero.jpg',
            'is_mobile_cover' => true,
        ]);

        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('<source media="(max-width: 767px)" srcset="https://example.test/mobile-hero.jpg">', false);

        $edition->update(['registration_status' => 'closed']);

        $this->get($publicUrl)
            ->assertOk()
            ->assertDontSee(__('app.festival_apply'))
            ->assertSee(__('app.buy_tickets'))
            ->assertSee(__('app.festival_participant_cabinet'))
            ->assertSee(__('app.festival_judge_cabinet'));
    }

    public function test_velvet_night_uses_active_structured_data_and_authored_jury_content_without_decorative_numbers(): void
    {
        [$account, , $edition] = $this->ownerEdition([
            'allowed_festival_landing_templates' => ['velvet_night'],
        ], [
            'status' => 'published',
            'registration_status' => 'open',
            'landing_template' => 'velvet_night',
            'published_at' => now(),
            'registration_opens_at' => '2030-09-03 10:00:00',
            'registration_closes_at' => '2030-09-10 10:00:00',
            'starts_at' => '2030-09-20 10:00:00',
            'ends_at' => '2030-09-20 18:00:00',
        ]);

        foreach ([
            ['key' => 'important-dates', 'title' => 'Live dates', 'body_html' => '<p>Authored dates sentinel</p>'],
            ['key' => 'jury', 'title' => 'Live jury', 'body_html' => '<p>Authored Head Judge</p><p>Authored Judge</p>'],
            ['key' => 'stage', 'title' => 'Live stages', 'body_html' => '<p>Authored stage sentinel</p>'],
            ['key' => 'payments', 'title' => 'Live fees', 'body_html' => '<p>Authored fees sentinel</p>'],
            ['key' => 'ordinary', 'title' => 'Ordinary section', 'body_html' => '<p>Ordinary body sentinel</p>'],
        ] as $sortOrder => $section) {
            FestivalContentSection::query()->create([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'sort_order' => $sortOrder,
                ...$section,
            ]);
        }

        $workflow = FestivalWorkflow::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'name' => 'Active workflow',
        ]);
        $activeStep = FestivalWorkflowStep::factory()->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'title' => 'Live application deadline',
            'due_at' => '2030-09-12 10:00:00',
        ]);
        FestivalWorkflowStep::factory()->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
            'title' => 'Inactive step sentinel',
            'due_at' => '2030-09-13 10:00:00',
            'is_active' => false,
        ]);

        FestivalStage::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'name' => 'Live database stage',
            'description' => 'Live stage dimensions',
        ]);
        FestivalStage::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'name' => 'Inactive stage sentinel',
            'is_active' => false,
        ]);

        FestivalChargeDefinition::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_workflow_step_id' => $activeStep->id,
            'name' => 'Live fixed fee',
            'amount_cents' => 12345,
            'currency' => 'USD',
            'due_at' => '2030-09-15 10:00:00',
        ]);
        FestivalChargeDefinition::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_workflow_step_id' => $activeStep->id,
            'name' => 'Live roster fee',
            'amount_cents' => 32000,
            'pricing_mode' => 'roster',
            'included_members' => 2,
            'additional_member_amount_cents' => 4000,
            'currency' => 'EUR',
            'due_at' => '2030-09-16 10:00:00',
        ]);
        FestivalChargeDefinition::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'name' => 'Inactive fee sentinel',
            'amount_cents' => 99999,
            'currency' => 'GBP',
            'is_active' => false,
        ]);
        FestivalJudgeAssignment::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'display_name' => 'Inactive Judge Sentinel',
            'is_active' => false,
        ]);

        $publicUrl = route('public.festivals.show', [$account->slug, $edition->slug]);
        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('Live dates')
            ->assertSee('03.09.2030')
            ->assertSee('12.09.2030')
            ->assertSee('15.09.2030')
            ->assertSee('20.09.2030')
            ->assertSee('Live database stage')
            ->assertSee('Live stage dimensions')
            ->assertSee(MoneyFormatter::format(12345, 'USD'))
            ->assertSee(MoneyFormatter::format(32000, 'EUR'))
            ->assertSee(__('app.festival_public_roster_fee', [
                'count' => 2,
                'amount' => MoneyFormatter::format(4000, 'EUR'),
            ]))
            ->assertSee('Ordinary body sentinel')
            ->assertSee('Live jury')
            ->assertSee('Authored Head Judge')
            ->assertSee('Authored Judge')
            ->assertDontSee('Inactive Judge Sentinel')
            ->assertDontSee('Inactive step sentinel')
            ->assertDontSee('Inactive stage sentinel')
            ->assertDontSee('Inactive fee sentinel')
            ->assertDontSee('Authored dates sentinel')
            ->assertDontSee('Authored stage sentinel')
            ->assertDontSee('Authored fees sentinel')
            ->assertDontSee('velvet-card-number', false)
            ->assertDontSee('velvet-section-index', false);

        FestivalJudgeAssignment::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'display_name' => 'Live Head Judge',
            'is_head_judge' => true,
        ]);
        FestivalJudgeAssignment::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'display_name' => 'Live Judge',
        ]);

        $this->get($publicUrl)
            ->assertOk()
            ->assertSee('Live jury')
            ->assertSee('Authored Head Judge')
            ->assertSee('Authored Judge')
            ->assertDontSee('Live Head Judge')
            ->assertDontSee('Live Judge')
            ->assertDontSee('Inactive Judge Sentinel')
            ->assertDontSee(__('app.festival_head_judge'));

        $edition->sections()->where('key', 'jury')->delete();

        $this->get($publicUrl)
            ->assertOk()
            ->assertDontSee('Live jury')
            ->assertDontSee('Authored Head Judge')
            ->assertDontSee('Authored Judge')
            ->assertDontSee('Live Head Judge')
            ->assertDontSee('Live Judge');
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
