<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalContentSection;
use App\Models\FestivalDirection;
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

    public function test_owner_can_manage_directions_categories_registration_fields_fees_and_content(): void
    {
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.directions.store', [$account, $edition]), [
            'name' => 'Повітряне кільце',
        ])->assertRedirect();
        $direction = FestivalDirection::query()->where('festival_edition_id', $edition->id)->where('code', 'povitryane-kiltse')->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.workflows.store', [$account, $edition]), [
            'name' => 'Основна реєстрація',
            'application_review_mode' => 'organizer',
            'technical_review_mode' => 'automatic',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.workflows', [$account, $edition]));
        $workflow = FestivalWorkflow::query()->where('festival_edition_id', $edition->id)->where('name', 'Основна реєстрація')->with('steps')->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'name' => 'Соло — кільце',
            'festival_direction_id' => $direction->id,
            'festival_workflow_id' => $workflow->id,
            'min_members' => 1,
            'max_members' => 1,
            'requirements_html' => '<h2 onclick="bad()">Умови</h2><script>bad()</script><p style="text-align: center; color: red;">Безпечний текст.</p>',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));
        $category = FestivalCategory::query()->where('festival_edition_id', $edition->id)->where('code', 'solo-kiltse')->firstOrFail();
        $this->assertTrue($category->direction->is($direction));
        $this->assertSame('<h2>Умови</h2><p style="text-align: center;">Безпечний текст.</p>', $category->requirements_html);

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
        $registrationField = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->where('code', 'kvalifikatsiyne-video')->firstOrFail();
        $this->assertSame(['mp4', 'mov'], $registrationField->allowed_extensions);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]))
            ->assertOk()
            ->assertSee('Поля реєстрації')
            ->assertSee('Кваліфікаційне відео');

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.charge-definitions.store', [$account, $edition]), [
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $applicationStep->id,
            'kind' => 'qualification',
            'name' => 'Кваліфікаційний внесок',
            'amount_cents' => 50000,
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.fees', [$account, $edition]));
        $this->assertSame('UAH', FestivalChargeDefinition::query()->where('festival_edition_id', $edition->id)->firstOrFail()->currency);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.content.store', [$account, $edition]), [
            'title' => 'Для учасників',
            'body_html' => '<p>Актуальна інформація.</p>',
            'visibility' => 'public',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content', [$account, $edition]));
        $this->assertSame('dlya-uchasnykiv', FestivalContentSection::query()->where('festival_edition_id', $edition->id)->firstOrFail()->key);
    }

    public function test_referenced_direction_and_workflow_cannot_be_deactivated(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'festival_workflow_id' => $workflow->id,
        ]);

        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.directions.toggle', [$account, $edition, $direction]))
            ->assertRedirect()
            ->assertSessionHasErrors('direction');
        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.directions.update', [$account, $edition, $direction]), [
                'name' => $direction->name,
                'is_active' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('direction');
        $this->actingAs($owner)
            ->patch(route('dashboard.accounts.festivals.workflows.toggle', [$account, $edition, $workflow]))
            ->assertRedirect()
            ->assertSessionHasErrors('workflow');

        $this->assertTrue($direction->refresh()->is_active);
        $this->assertTrue($workflow->refresh()->is_active);
    }

    public function test_direction_codes_are_collision_safe_stable_hidden_and_orderable(): void
    {
        [$account, $edition, $owner] = $this->festival();

        foreach (['Повітряне кільце', 'Повітряне кільце'] as $name) {
            $this->actingAs($owner)->post(route('dashboard.accounts.festivals.directions.store', [$account, $edition]), [
                'name' => $name,
            ])->assertRedirect();
        }

        $directions = FestivalDirection::query()->where('festival_edition_id', $edition->id)->orderBy('id')->get();
        $this->assertSame(['povitryane-kiltse', 'povitryane-kiltse-2'], $directions->pluck('code')->all());

        $first = $directions->firstOrFail();
        $second = $directions->last();
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.directions.update', [$account, $edition, $first]), [
            'name' => 'Новий напрямок',
            'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame('povitryane-kiltse', $first->refresh()->code);

        $this->actingAs($owner)->patch(route('dashboard.accounts.festivals.directions.move', [$account, $edition, $second]), [
            'direction' => 'up',
        ])->assertRedirect();
        $this->assertLessThan($first->refresh()->sort_order, $second->refresh()->sort_order);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertDontSee('classification', false)
            ->assertSee('data-festival-edit-toggle', false);
    }

    public function test_category_create_and_edit_pages_are_grouped_sanitized_and_timezone_aware(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $edition->forceFill(['timezone' => 'Europe/Kyiv'])->save();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Pole Art']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]))
            ->assertOk()
            ->assertSee(route('dashboard.accounts.festivals.categories.create', [$account, $edition]), false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('data-studio-rules-editor', false);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.create', [$account, $edition]))
            ->assertOk()
            ->assertSeeInOrder([
                __('app.festival_category_details'),
                __('app.festival_category_eligibility'),
                __('app.festival_category_performance'),
                __('app.festival_category_registration'),
                __('app.festival_category_requirements'),
            ])
            ->assertSee('data-studio-rules-editor', false)
            ->assertSee('name="festival_direction_id"', false)
            ->assertDontSee('name="workflow"', false)
            ->assertDontSee('name="option_ids', false);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'name' => 'Junior Pole Art',
            'festival_direction_id' => $direction->id,
            'min_members' => 1,
            'max_members' => 2,
            'min_age' => 8,
            'max_age' => 12,
            'min_duration_seconds' => 120,
            'max_duration_seconds' => 180,
            'registration_closes_at' => '2026-08-20T18:30',
            'requirements_html' => '<p><br></p>',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));

        $category = FestivalCategory::query()->where('festival_edition_id', $edition->id)->where('code', 'junior-pole-art')->firstOrFail();
        $this->assertNull($category->requirements_html);
        $this->assertSame('2026-08-20 15:30:00', $category->registration_closes_at->utc()->format('Y-m-d H:i:s'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->assertOk()
            ->assertSee('value="2026-08-20T18:30"', false)
            ->assertSee('data-studio-rules-editor', false);

        $this->actingAs($owner)
            ->from(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->put(route('dashboard.accounts.festivals.categories.update', [$account, $edition, $category]), [
                'name' => 'Junior Pole Art',
                'festival_direction_id' => $direction->id,
                'min_members' => 3,
                'max_members' => 2,
            ])
            ->assertRedirect(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->assertSessionHasErrors('max_members');
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.edit', [$account, $edition, $category]))
            ->assertSee('name="min_members" value="3"', false);
    }

    public function test_category_and_direction_dependencies_are_tenant_scoped_and_manager_only(): void
    {
        [$account, $edition, $owner] = $this->festival();
        [$otherAccount, $otherEdition] = $this->festival();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $otherDirection = FestivalDirection::factory()->for($otherEdition)->create(['account_id' => $otherAccount->id]);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.categories.store', [$account, $edition]), [
            'name' => 'Cross tenant category',
            'festival_direction_id' => $otherDirection->id,
            'min_members' => 1,
            'max_members' => 1,
        ])->assertSessionHasErrors('festival_direction_id');
        $this->assertDatabaseMissing('festival_categories', ['festival_edition_id' => $edition->id, 'name' => 'Cross tenant category']);

        $category = FestivalCategory::factory()->for($edition)->for($direction)->create(['account_id' => $account->id]);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.edit', [$account, $otherEdition, $category]))
            ->assertNotFound();

        $finance = User::factory()->create();
        $account->users()->attach($finance->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivalFinance->value],
        ]);
        $this->actingAs($finance)->get(route('dashboard.accounts.festivals.categories.create', [$account, $edition]))->assertForbidden();
        $this->actingAs($finance)->post(route('dashboard.accounts.festivals.directions.store', [$account, $edition]), ['name' => 'Forbidden'])->assertForbidden();
        $this->actingAs($finance)->patch(route('dashboard.accounts.festivals.categories.move', [$account, $edition, $category]), ['direction' => 'up'])->assertForbidden();
    }

    public function test_registration_field_choice_identifiers_remain_stable_and_hidden(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $workflow = FestivalWorkflow::factory()->for($edition)->create(['account_id' => $account->id]);
        $step = $workflow->steps()->create([
            'account_id' => $account->id,
            'code' => 'technical-form',
            'type' => 'form',
            'title' => 'Технічна анкета',
            'sort_order' => 10,
            'review_mode' => 'organizer',
            'review_effect' => 'none',
        ]);
        $payload = [
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

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), $payload)->assertRedirect();
        $field = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->firstOrFail();
        $this->assertSame(['standart', 'standart-2'], collect($field->options)->pluck('value')->all());

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.requirements.update', [$account, $edition, $field]), [
            ...$payload,
            'name' => 'Оновлений костюм',
            'options' => [
                ['original_value' => 'standart-2', 'label' => 'Преміум', 'price_cents' => 300],
                ['original_value' => 'standart', 'label' => 'Базовий', 'price_cents' => 175],
            ],
        ])->assertRedirect();
        $this->assertSame('variant-kostyuma', $field->refresh()->code);
        $this->assertSame(['standart-2', 'standart'], collect($field->options)->pluck('value')->all());

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.requirements', [$account, $edition]))
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertDontSee('][value]', false)
            ->assertSee(__('app.festival_registration_fields'));
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
