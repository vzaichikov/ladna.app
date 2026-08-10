<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalClassificationAxis;
use App\Models\FestivalContentSection;
use App\Models\FestivalEdition;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflow;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FestivalSettingsManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_manage_taxonomy_registration_and_content_from_focused_pages(): void
    {
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axes.store', [$account, $edition]), [
            'name' => 'Снаряд',
            'kind' => 'direction',
            'is_required' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]));
        $axis = FestivalClassificationAxis::query()->where('festival_edition_id', $edition->id)->where('code', 'snaryad')->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axis-options.store', [$account, $edition, $axis]), [
            'label' => 'Кільце',
        ])->assertRedirect();
        $option = $axis->options()->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.workflows.store', [$account, $edition]), [
            'name' => 'Основна реєстрація',
            'application_review_mode' => 'organizer',
            'technical_review_mode' => 'automatic',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.workflows', [$account, $edition]));
        $workflow = FestivalWorkflow::query()->where('festival_edition_id', $edition->id)->where('name', 'Основна реєстрація')->with('steps')->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'name' => 'Соло — кільце',
            'festival_workflow_id' => $workflow->id,
            'workflow' => 'qualification',
            'min_members' => 1,
            'max_members' => 1,
            'option_ids' => [$option->id],
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));
        $category = FestivalCategory::query()->where('festival_edition_id', $edition->id)->where('code', 'solo-kiltse')->firstOrFail();
        $this->assertTrue($category->options()->whereKey($option->id)->exists());

        $applicationStep = $workflow->steps->first();
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), [
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $applicationStep->id,
            'type' => 'qualification_video',
            'subject_scope' => 'entry',
            'input_type' => 'file',
            'name' => 'Кваліфікаційне відео',
            'pricing_mode' => 'none',
            'stage' => 'qualification',
            'max_size_kb' => 51200,
            'allowed_extensions_text' => 'mp4, mov',
            'allowed_mime_types_text' => 'video/mp4, video/quicktime',
            'is_required' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]));
        $requirement = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->where('code', 'kvalifikatsiyne-video')->firstOrFail();
        $this->assertSame(['mp4', 'mov'], $requirement->allowed_extensions);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]), [
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $applicationStep->id,
            'kind' => 'qualification',
            'name' => 'Кваліфікаційний внесок',
            'amount_cents' => 50000,
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.fees', [$account, $edition]));
        $fee = FestivalChargeDefinition::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $this->assertSame('UAH', $fee->currency);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.content.store', [$account, $edition]), [
            'title' => 'Для учасників',
            'body_html' => '<p>Актуальна інформація.</p>',
            'visibility' => 'public',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content', [$account, $edition]));
        $section = FestivalContentSection::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $this->assertSame('dlya-uchasnykiv', $section->key);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.content.update', [$account, $edition, $section]), [
            'title' => 'Важливо для учасників',
            'body_html' => '<p>Оновлена інформація.</p>',
            'visibility' => 'public',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content', [$account, $edition]));
        $this->assertSame('Важливо для учасників', $section->refresh()->title);
        $this->assertSame('dlya-uchasnykiv', $section->key);

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.requirements.toggle', [$account, $edition, $requirement]))->assertRedirect();
        $this->assertFalse($requirement->refresh()->is_active);
    }

    public function test_referenced_taxonomy_and_workflow_dependencies_cannot_be_deactivated(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $axis = $edition->axes()->create(['account_id' => $account->id, 'code' => 'direction', 'name' => 'Напрямок', 'kind' => 'direction']);
        $option = $axis->options()->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id, 'code' => 'silks', 'label' => 'Полотна']);
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id, 'festival_workflow_id' => $workflow->id]);
        $category->options()->attach($option->id, ['account_id' => $account->id]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.axis-options.toggle', [$account, $edition, $axis, $option]))
            ->assertRedirect()
            ->assertSessionHasErrors('option');
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.axes.toggle', [$account, $edition, $axis]))
            ->assertRedirect()
            ->assertSessionHasErrors('axis');
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflows.toggle', [$account, $edition, $workflow]))
            ->assertRedirect()
            ->assertSessionHasErrors('workflow');

        $this->assertTrue($axis->refresh()->is_active);
        $this->assertTrue($option->refresh()->is_active);
        $this->assertTrue($workflow->refresh()->is_active);
    }

    public function test_direction_codes_are_generated_once_and_hidden_from_the_directions_interface(): void
    {
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axes.store', [$account, $edition]), [
            'name' => 'Повітряні напрямки',
            'kind' => 'direction',
            'is_required' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]));

        $axis = FestivalClassificationAxis::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $this->assertSame('povitryani-napryamky', $axis->code);

        foreach (['Повітряне кільце', 'Повітряне кільце'] as $label) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axis-options.store', [$account, $edition, $axis]), [
                'label' => $label,
            ])->assertRedirect();
        }

        $options = $axis->options()->orderBy('id')->get();
        $this->assertSame(['povitryane-kiltse', 'povitryane-kiltse-2'], $options->pluck('code')->all());

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axis-options.store', [$account, $edition, $axis]), [
            'label' => str_repeat('Щ', 255),
        ])->assertRedirect();
        $this->assertLessThanOrEqual(100, strlen($axis->options()->latest('id')->firstOrFail()->code));

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.axes.update', [$account, $edition, $axis]), [
            'name' => 'Оновлена група',
            'kind' => 'direction',
            'is_required' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]));
        $this->assertSame('povitryani-napryamky', $axis->refresh()->code);

        $firstOption = $options->firstOrFail();
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.axis-options.update', [$account, $edition, $axis, $firstOption]), [
            'label' => 'Новий напрямок',
            'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame('povitryane-kiltse', $firstOption->refresh()->code);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertDontSee('<select name="kind"', false)
            ->assertSee('data-festival-edit-toggle', false)
            ->assertSee(__('app.festival_direction_group_name_help'))
            ->assertDontSee('Внутрішній slug');

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.classifications', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertSee('<select name="kind"', false);
    }

    public function test_all_settings_identifiers_are_automatic_collision_safe_stable_and_hidden(): void
    {
        [$account, $edition, $owner] = $this->festival();

        foreach (['Рівень', 'Рівень'] as $name) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axes.store', [$account, $edition]), [
                'name' => $name,
                'kind' => 'level',
                'is_required' => 1,
            ])->assertRedirect(route('dashboard.accounts.festivals.settings.classifications', [$account, $edition]));
        }

        $axes = FestivalClassificationAxis::query()->where('festival_edition_id', $edition->id)->orderBy('id')->get();
        $this->assertSame(['riven', 'riven-2'], $axes->pluck('code')->all());
        $axis = $axes->firstOrFail();

        foreach (['Початківець', 'Початківець'] as $label) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axis-options.store', [$account, $edition, $axis]), [
                'label' => $label,
            ])->assertRedirect();
        }
        $options = $axis->options()->orderBy('id')->get();
        $this->assertSame(['pochatkivets', 'pochatkivets-2'], $options->pluck('code')->all());

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.axes.update', [$account, $edition, $axis]), [
            'name' => 'Досвід',
            'kind' => 'level',
            'is_required' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.classifications', [$account, $edition]));
        $this->assertSame('riven', $axis->refresh()->code);

        $option = $options->firstOrFail();
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.axis-options.update', [$account, $edition, $axis, $option]), [
            'label' => 'Профі',
            'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame('pochatkivets', $option->refresh()->code);

        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $stepPayload = [
            'type' => 'form',
            'title' => 'Технічна анкета',
            'sort_order' => 10,
            'review_mode' => 'organizer',
            'review_effect' => 'none',
            'is_active' => 1,
        ];
        foreach ([1, 2] as $unused) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.workflow-steps.store', [$account, $edition, $workflow]), $stepPayload)
                ->assertRedirect(route('dashboard.accounts.festivals.settings.workflows', [$account, $edition]));
        }
        $steps = $workflow->steps()->orderBy('id')->get();
        $this->assertSame(['tekhnichna-anketa', 'tekhnichna-anketa-2'], $steps->pluck('code')->all());
        $step = $steps->firstOrFail();

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.workflow-steps.update', [$account, $edition, $workflow, $step]), [
            ...$stepPayload,
            'title' => 'Оновлена анкета',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.workflows', [$account, $edition]));
        $this->assertSame('tekhnichna-anketa', $step->refresh()->code);

        $categoryPayload = [
            'name' => 'Соло — кільце',
            'festival_workflow_id' => $workflow->id,
            'workflow' => 'direct',
            'min_members' => 1,
            'max_members' => 1,
            'is_active' => 1,
        ];
        foreach ([1, 2] as $unused) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), $categoryPayload)
                ->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));
        }
        $categories = FestivalCategory::query()->where('festival_edition_id', $edition->id)->orderBy('id')->get();
        $this->assertSame(['solo-kiltse', 'solo-kiltse-2'], $categories->pluck('code')->all());
        $category = $categories->firstOrFail();

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.categories.update', [$account, $edition, $category]), [
            ...$categoryPayload,
            'name' => 'Соло — полотна',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));
        $this->assertSame('solo-kiltse', $category->refresh()->code);

        $requirementPayload = [
            'festival_workflow_step_id' => $step->id,
            'type' => 'custom_document',
            'subject_scope' => 'entry',
            'input_type' => 'single_select',
            'name' => 'Варіант костюма',
            'pricing_mode' => 'option_prices',
            'stage' => 'final',
            'max_size_kb' => 20480,
            'options' => [
                ['label' => 'Стандарт', 'price_cents' => 100],
                ['label' => 'Стандарт', 'price_cents' => 200],
            ],
            'is_required' => 1,
            'is_active' => 1,
        ];
        foreach ([1, 2] as $unused) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), $requirementPayload)
                ->assertRedirect(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]));
        }
        $requirements = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->orderBy('id')->get();
        $this->assertSame(['variant-kostyuma', 'variant-kostyuma-2'], $requirements->pluck('code')->all());
        $requirement = $requirements->firstOrFail();
        $this->assertSame(['standart', 'standart-2'], collect($requirement->options)->pluck('value')->all());
        $this->assertSame(['standart' => 100, 'standart-2' => 200], $requirement->pricing['prices']);

        $legacyRequirement = $requirements->last();
        $legacyRequirement->forceFill(['code' => null])->save();
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $legacyRequirement]), [
            ...$requirementPayload,
            'options' => [
                ['original_value' => 'standart', 'label' => 'Стандарт', 'price_cents' => 100],
                ['original_value' => 'standart-2', 'label' => 'Стандарт', 'price_cents' => 200],
            ],
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]));
        $this->assertSame('variant-kostyuma-2', $legacyRequirement->refresh()->code);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $requirement]), [
            ...$requirementPayload,
            'name' => 'Оновлений костюм',
            'options' => [
                ['original_value' => 'standart', 'label' => 'Базовий', 'price_cents' => 150],
                ['original_value' => 'standart-2', 'label' => 'Преміум', 'price_cents' => 250],
            ],
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]));
        $requirement->refresh();
        $this->assertSame('variant-kostyuma', $requirement->code);
        $this->assertSame(['standart', 'standart-2'], collect($requirement->options)->pluck('value')->all());
        $this->assertSame(['standart' => 150, 'standart-2' => 250], $requirement->pricing['prices']);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $requirement]), [
            ...$requirementPayload,
            'name' => 'Оновлений костюм',
            'options' => [
                ['original_value' => 'standart-2', 'label' => 'Преміум', 'price_cents' => 300],
                ['original_value' => 'standart', 'label' => 'Базовий', 'price_cents' => 175],
            ],
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]));
        $requirement->refresh();
        $this->assertSame(['standart-2', 'standart'], collect($requirement->options)->pluck('value')->all());
        $this->assertSame(['standart-2' => 300, 'standart' => 175], $requirement->pricing['prices']);

        foreach ([1, 2] as $unused) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.content.store', [$account, $edition]), [
                'title' => 'Для учасників',
                'body_html' => '<p>Актуальна інформація.</p>',
                'visibility' => 'public',
            ])->assertRedirect(route('dashboard.accounts.festivals.settings.content', [$account, $edition]));
        }
        $sections = FestivalContentSection::query()->where('festival_edition_id', $edition->id)->orderBy('id')->get();
        $this->assertSame(['dlya-uchasnykiv', 'dlya-uchasnykiv-2'], $sections->pluck('key')->all());
        $section = $sections->firstOrFail();

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.content.update', [$account, $edition, $section]), [
            'title' => 'Оновлення для учасників',
            'body_html' => '<p>Оновлена інформація.</p>',
            'visibility' => 'public',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content', [$account, $edition]));
        $this->assertSame('dlya-uchasnykiv', $section->refresh()->key);

        foreach (['directions', 'classifications', 'categories', 'workflows', 'requirements', 'content'] as $page) {
            $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.settings.'.$page, [$account, $edition]));
            $response->assertOk()->assertDontSee('name="code"', false);
        }
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.content', [$account, $edition]))
            ->assertDontSee('name="key"', false)
            ->assertDontSee('dlya-uchasnykiv');
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]))
            ->assertDontSee('][value]', false);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.classifications', [$account, $edition]))
            ->assertDontSee('pochatkivets');
    }

    public function test_finance_staff_can_manage_fees_but_not_taxonomy(): void
    {
        [$account, $edition] = $this->festival();
        $finance = User::factory()->create();
        $account->users()->attach($finance->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivalFinance->value],
        ]);
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = $workflow->steps()->create(['account_id' => $account->id, 'code' => 'payment', 'type' => 'payment', 'title' => 'Оплата', 'sort_order' => 10, 'review_mode' => 'automatic', 'review_effect' => 'none']);

        $this->actingAs($finance)->post(route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]), [
            'festival_workflow_step_id' => $step->id,
            'kind' => 'participation',
            'name' => 'Участь',
            'amount_cents' => 100000,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.fees', [$account, $edition]));
        $this->actingAs($finance)->post(route('dashboard.accounts.festivals.axes.store', [$account, $edition]), [
            'code' => 'level',
            'name' => 'Рівень',
            'kind' => 'level',
        ])->assertForbidden();

        $this->assertDatabaseHas('festival_charge_definitions', ['festival_edition_id' => $edition->id, 'name' => 'Участь']);
        $this->assertDatabaseMissing('festival_classification_axes', ['festival_edition_id' => $edition->id, 'code' => 'level']);
    }

    /** @return array{Account, FestivalEdition, User} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'uk']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        return [$account, $edition, $owner];
    }
}
