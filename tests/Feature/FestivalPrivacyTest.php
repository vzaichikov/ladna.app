<?php

namespace Tests\Feature;

use App\Actions\Festivals\SaveFestivalBranding;
use App\Actions\Festivals\SaveFestivalEdition;
use App\Actions\Festivals\SaveFestivalSponsor;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalEntryStep;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalSponsor;
use App\Models\FestivalSubmission;
use App\Models\FestivalTicketOrder;
use App\Models\User;
use App\Support\Festivals\FestivalTelegramMiniAppData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalPrivacyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_private_editions_are_hidden_from_public_and_registrant_directories_and_mini_app(): void
    {
        [$account, $series, $edition] = $this->festival();
        $publicEdition = FestivalEdition::factory()->for($series)->published()->create([
            'account_id' => $account->id,
            'title' => 'Відкритий фестиваль',
            'show_on_studio_page' => true,
        ]);
        $registrant = FestivalPortalUser::factory()->for($account)->create();

        foreach (['published', 'in_progress', 'completed'] as $status) {
            $edition->update(['status' => $status]);
            $this->get(route('public.festivals.index', $account->slug))
                ->assertOk()->assertSee($publicEdition->title)->assertDontSee($edition->title);
            $this->get(route('public.studio', $account->slug))
                ->assertOk()->assertDontSee($edition->title);
            $this->actingAs($registrant, 'festival')
                ->get(route('festival.portal.dashboard', $account->slug))
                ->assertOk()->assertSee($publicEdition->title)->assertDontSee($edition->title);
            $data = app(FestivalTelegramMiniAppData::class)->build($series);
            $this->assertSame([$publicEdition->id], array_column($data['editions'], 'id'));
            $this->assertSame([$publicEdition->id], array_column(app(FestivalTelegramMiniAppData::class)->timelineUpdates($series), 'id'));
        }
    }

    public function test_private_public_routes_and_documents_return_not_found_even_for_the_owner(): void
    {
        Storage::fake('local');
        [$account, , $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Storage::disk('local')->put('festivals/private-rules.txt', 'Закриті правила');
        $document = FestivalDocument::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'title' => 'Правила',
            'disk' => 'local',
            'path' => 'festivals/private-rules.txt',
            'original_name' => 'rules.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 30,
            'visibility' => 'public',
        ]);

        foreach (['public.festivals.show', 'public.festivals.timeline', 'public.festivals.entrance'] as $route) {
            $this->get(route($route, [$account->slug, $edition->slug]))->assertNotFound();
            $this->actingAs($owner)->get(route($route, [$account->slug, $edition->slug]))->assertNotFound();
        }
        $this->get(route('public.festival-documents.download', [$account->slug, $document]))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $edition]))
            ->assertOk()->assertSee($edition->title)->assertSee(__('app.festival_private'))
            ->assertDontSee(route('public.festivals.show', [$account->slug, $edition->slug]), false);
    }

    public function test_existing_ticket_tokens_stop_exposing_a_private_edition(): void
    {
        [$account, , $edition] = $this->festival();
        $order = FestivalTicketOrder::factory()->for($edition, 'edition')->create([
            'account_id' => $account->id,
            'status' => 'paid',
        ]);
        $parameters = [$account->slug, $order->access_token_encrypted];

        foreach (['public.festival-orders.show', 'public.festival-orders.payment', 'public.festival-orders.status', 'public.festival-orders.pdf'] as $route) {
            $this->get(route($route, $parameters))->assertNotFound();
        }
    }

    public function test_a_registrant_cannot_see_or_open_their_own_private_edition_entries(): void
    {
        [$account, , $edition] = $this->festival();
        $registrant = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($edition, 'edition')->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'festival_portal_user_id' => $registrant->id,
            'entry_name' => 'Нічна пташка',
        ]);
        $this->actingAs($registrant, 'festival')->get(route('festival.portal.entries.index', $account->slug))
            ->assertOk()->assertDontSee($entry->entry_name)->assertDontSee($edition->title);
        foreach (['festival.portal.entries.show', 'festival.portal.entries.edit'] as $route) {
            $this->actingAs($registrant, 'festival')->get(route($route, [$account->slug, $entry]))->assertNotFound();
        }
        $this->actingAs($registrant, 'festival')
            ->get(route('festival.portal.entries.create', [$account->slug, $edition->slug]))->assertNotFound();
    }

    public function test_assigned_judges_can_use_the_real_private_scoring_flow_but_other_judges_cannot(): void
    {
        [$account, , $edition] = $this->festival();
        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $assignment = FestivalJudgeAssignment::factory()->for($edition, 'edition')->create([
            'account_id' => $account->id,
            'user_id' => null,
            'festival_portal_user_id' => $judge->id,
        ]);
        $category = FestivalCategory::factory()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($edition, 'edition')->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'entry_name' => 'Оксана Зоряна',
            'status' => 'accepted',
        ]);
        $rubric = FestivalRubric::factory()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $section = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Артистизм', 'weight' => 1]);
        $criterion = $section->criteria()->create(['account_id' => $account->id, 'name' => 'Образ', 'max_score' => 10, 'weight' => 1]);
        $sheet = FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_judge_assignment_id' => $assignment->id,
            'festival_rubric_id' => $rubric->id,
        ]);
        $index = route('festival.portal.judging.index', [$account->slug, $edition]);
        $edit = route('festival.portal.judging.edit', [$account->slug, $sheet]);

        $this->actingAs($judge, 'festival')->get(route('festival.portal.judge.dashboard', $account->slug))
            ->assertOk()->assertSee($edition->title);
        $this->actingAs($judge, 'festival')->get($index)->assertOk()->assertSee($entry->entry_name);
        $this->actingAs($judge, 'festival')->get($edit)->assertOk()->assertSee($criterion->name);
        $this->actingAs($judge, 'festival')->putJson(route('festival.portal.judging.update', [$account->slug, $sheet]), [
            'scores' => [['criterion_id' => $criterion->id, 'score' => 8, 'comment' => 'Виразний образ']],
        ])->assertOk()->assertJsonPath('progress.ready', true);

        $otherJudge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $this->actingAs($otherJudge, 'festival')->get($index)->assertNotFound();
        $this->actingAs($otherJudge, 'festival')->get($edit)->assertNotFound();
        $assignment->update(['is_active' => false]);
        $this->actingAs($judge, 'festival')->get($index)->assertNotFound();
    }

    public function test_private_submission_and_workflow_resources_are_denied_to_the_registrant_but_staff_can_download(): void
    {
        Storage::fake('local');
        [$account, , $edition] = $this->festival();
        $registrant = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($edition, 'edition')->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'festival_portal_user_id' => $registrant->id,
        ]);
        $step = FestivalEntryStep::factory()->create(['account_id' => $account->id, 'festival_entry_id' => $entry->id]);
        $definition = FestivalRequirementDefinition::factory()->create(['account_id' => $account->id, 'festival_edition_id' => $edition->id]);
        $requirement = FestivalEntryRequirement::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_entry_step_id' => $step->id,
            'festival_requirement_definition_id' => $definition->id,
            'subject_key' => 'entry',
        ]);
        Storage::disk('local')->put('festivals/private-music.mp3', 'private music');
        $submission = FestivalSubmission::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_entry_requirement_id' => $requirement->id,
            'festival_portal_user_id' => $registrant->id,
            'disk' => 'local',
            'path' => 'festivals/private-music.mp3',
            'original_name' => 'music.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 13,
        ]);
        $this->actingAs($registrant, 'festival')
            ->get(route('festival.portal.submissions.download', [$account->slug, $submission]))->assertNotFound();
        $this->actingAs($registrant, 'festival')
            ->get(route('festival.portal.entry-steps.show', [$account->slug, $entry, $step]))->assertNotFound();
        $this->actingAs($registrant, 'festival')
            ->get(route('festival.portal.submissions.fragment', [$account->slug, $entry, $requirement]))->assertNotFound();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $this->actingAs($owner, 'web')->get(route('dashboard.accounts.festivals.submissions.download', [$account, $submission]))->assertOk();
    }

    public function test_privacy_defaults_to_public_and_is_saved_through_the_existing_edition_action(): void
    {
        [$account, $series, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $input = [
            'festival_series_id' => $series->id,
            'title' => $edition->title,
            'status' => 'published',
            'registration_status' => 'closed',
            'timezone' => 'Europe/Kyiv',
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
            'age_reference_date' => now()->toDateString(),
            'is_private' => false,
        ];
        app(SaveFestivalEdition::class)->execute($account, $input, $owner, $edition);
        $this->assertFalse($edition->refresh()->is_private);
        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))->assertOk();

        $input['is_private'] = true;
        $input['show_on_studio_page'] = true;
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $input)->assertRedirect();
        $this->assertTrue($edition->refresh()->is_private);
        $this->assertFalse($edition->show_on_studio_page);
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.edit', [$account, $edition]))
            ->assertOk()->assertSee('name="is_private"', false);
        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))->assertNotFound();
        $publicEdition = FestivalEdition::factory()->for($series)->create(['account_id' => $account->id]);
        $this->assertFalse($publicEdition->is_private);
    }

    public function test_private_editions_cannot_publish_new_marketing_image_files(): void
    {
        Storage::fake('public');
        [$account, , $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $edition->is_private = false;
        try {
            app(SaveFestivalBranding::class)->execute($account, $edition, ['landing_palette' => 'general'], $owner, UploadedFile::fake()->image('cover.jpg'));
            $this->fail('Private Festival cover was published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hero_image', $exception->errors());
        }
        try {
            app(SaveFestivalSponsor::class)->execute($account, $edition, new FestivalSponsor, [
                'name' => 'Партнер', 'url' => null, 'short_description' => 'Опис',
                'giveaway_description' => null, 'show_on_all_pages' => false,
            ], UploadedFile::fake()->image('sponsor.jpg'));
            $this->fail('Private Festival sponsor logo was published.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('logo', $exception->errors());
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** @return array{Account, FestivalSeries, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'uk']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->for($series)->published()->create([
            'account_id' => $account->id,
            'title' => 'Закрита репетиція Чармпол',
            'show_on_studio_page' => true,
        ]);
        $edition->forceFill(['is_private' => true])->save();

        return [$account, $series, $edition];
    }
}
