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
            'code' => 'apparatus',
            'name' => 'Снаряд',
            'kind' => 'direction',
            'is_required' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.directions', [$account, $edition]));
        $axis = FestivalClassificationAxis::query()->where('festival_edition_id', $edition->id)->where('code', 'apparatus')->firstOrFail();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.axis-options.store', [$account, $edition, $axis]), [
            'code' => 'hoop',
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
            'code' => 'solo-hoop',
            'name' => 'Соло — кільце',
            'festival_workflow_id' => $workflow->id,
            'workflow' => 'qualification',
            'min_members' => 1,
            'max_members' => 1,
            'option_ids' => [$option->id],
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.categories', [$account, $edition]));
        $category = FestivalCategory::query()->where('festival_edition_id', $edition->id)->where('code', 'solo-hoop')->firstOrFail();
        $this->assertTrue($category->options()->whereKey($option->id)->exists());

        $applicationStep = $workflow->steps->first();
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.requirements.store', [$account, $edition]), [
            'festival_category_id' => $category->id,
            'festival_workflow_step_id' => $applicationStep->id,
            'code' => 'qualification-video',
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
        $requirement = FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->where('code', 'qualification-video')->firstOrFail();
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
            'key' => 'public-intro',
            'title' => 'Для учасників',
            'body_html' => '<p>Актуальна інформація.</p>',
            'visibility' => 'public',
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content', [$account, $edition]));
        $section = FestivalContentSection::query()->where('festival_edition_id', $edition->id)->firstOrFail();

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.content.update', [$account, $edition, $section]), [
            'key' => 'public-intro',
            'title' => 'Важливо для учасників',
            'body_html' => '<p>Оновлена інформація.</p>',
            'visibility' => 'public',
            'is_active' => 1,
        ])->assertRedirect(route('dashboard.accounts.festivals.settings.content', [$account, $edition]));
        $this->assertSame('Важливо для учасників', $section->refresh()->title);

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
