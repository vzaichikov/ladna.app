<?php

namespace Tests\Feature;

use App\Actions\Festivals\SyncCharmExoticFestival2026;
use App\Enums\FestivalCompetitionFormat;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalRegistrationStatus;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalEdition;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalSeries;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\App;
use RuntimeException;
use Tests\TestCase;

class CharmExoticFestivalImportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_import_repurposes_the_exact_empty_charmpole_edition_and_is_idempotent(): void
    {
        [$account, $edition] = $this->targetEdition();
        $sync = app(SyncCharmExoticFestival2026::class);

        $this->artisan('festivals:sync-charm-exotic-2026', [
            '--expected-account-id' => $account->id,
            '--expected-edition-id' => $edition->id,
        ])->expectsOutputToContain('Dry run only')->assertSuccessful();
        $preview = $sync->preview($account->id, $edition->id);

        $this->assertSame(12, $preview['category_count']);
        $this->assertSame(11, $preview['rubric_count']);
        $this->assertSame(0, $preview['current_counts']['admission_types']);
        $this->assertSame('Ladna Pole & Aerial Festival 2027', $edition->fresh()->title);

        $first = $sync->execute($account->id, $edition->id);
        $second = $sync->execute($account->id, $edition->id);
        $edition = $edition->fresh();

        $this->assertSame('Charm Exotic Pole Dance Fest — Autumn 2026', $edition->title);
        $this->assertSame(FestivalEditionStatus::Draft, $edition->status);
        $this->assertSame(FestivalRegistrationStatus::Closed, $edition->registration_status);
        $this->assertSame('Europe/Kyiv', $edition->timezone);
        $this->assertSame('2026-11-29', $edition->starts_at->timezone('Europe/Kyiv')->toDateString());
        $this->assertSame('00:00:00', $edition->starts_at->timezone('Europe/Kyiv')->format('H:i:s'));
        $this->assertSame('23:59:59', $edition->ends_at->timezone('Europe/Kyiv')->format('H:i:s'));
        $this->assertSame('2026-10-10 23:59:59', $edition->registration_closes_at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
        $this->assertSame($first['after'], $second['after']);
        $this->assertSame(12, $edition->categories()->where('is_active', true)->count());
        $this->assertSame(12, $edition->categories()->count());
        $this->assertSame(11, $edition->festivalRubrics()->where('is_active', true)->count());
        $this->assertSame(11, $edition->festivalRubrics()->count());
        $this->assertSame(13, $edition->festivalRequirementDefinitions()->where('is_active', true)->count());
        $this->assertSame(13, $edition->festivalRequirementDefinitions()->count());
        $this->assertSame(13, $edition->festivalChargeDefinitions()->where('is_active', true)->count());
        $this->assertSame(13, $edition->festivalChargeDefinitions()->count());
        $this->assertSame(1, $edition->stages()->where('is_active', true)->count());
        $this->assertSame(1, $edition->stages()->count());
        $this->assertSame(1, $edition->directions()->count());
        $this->assertSame(4, $edition->sections()->where('is_active', true)->count());
        $this->assertSame(4, $edition->sections()->count());
        $this->assertSame(0, $edition->judgeAssignments()->count());
        $this->assertSame(0, $edition->scheduleSlots()->count());
        $this->assertSame(0, $edition->admissionTypes()->count());
        $this->assertFalse($first['online_payment_ready']);

        $stage = $edition->stages()->where('is_active', true)->sole();
        $this->assertStringContainsString('4 м', $stage->description);
        $this->assertStringContainsString('42 мм', $stage->description);
        $this->assertStringContainsString('Саша Романова', $edition->sections()->where('key', 'jury')->value('body_html'));

        $expectedCategories = [
            'amateurs' => [1, 1, 18, 150, 195],
            'semi-professional' => [1, 1, 18, 165, 195],
            'profi-exotic-technique' => [1, 1, 18, 180, 240],
            'profi-exotic-musique-soul' => [1, 1, 18, 180, 240],
            'profi-exotic-art' => [1, 1, 18, 180, 240],
            'profi-exotic-duets' => [2, 100, 18, 180, 270],
            'hot-exot' => [1, 1, 18, 160, 220],
            'masters-amateurs-35' => [1, 1, 35, 165, 195],
            'masters-semi-pro-35' => [1, 1, 35, 165, 195],
            'masters-profi-35' => [1, 1, 35, 180, 240],
            'elite' => [1, 1, 18, 160, 300],
            'exotic-battles' => [1, 1, 18, null, null],
        ];
        foreach ($expectedCategories as $code => [$minimumMembers, $maximumMembers, $minimumAge, $minimumDuration, $maximumDuration]) {
            $category = $edition->categories()->where('code', $code)->firstOrFail();
            $this->assertSame($minimumMembers, $category->min_members, $code);
            $this->assertSame($maximumMembers, $category->max_members, $code);
            $this->assertSame($minimumAge, $category->min_age, $code);
            $this->assertSame($minimumDuration, $category->min_duration_seconds, $code);
            $this->assertSame($maximumDuration, $category->max_duration_seconds, $code);
            $this->assertSame(5, $category->minimum_entries_to_run, $code);
        }

        $battle = $edition->categories()->where('code', 'exotic-battles')->firstOrFail();
        $this->assertSame(FestivalCompetitionFormat::Knockout, $battle->competition_format);
        $this->assertSame(5, $battle->minimum_entries_to_run);
        $this->assertSame(18, $battle->min_age);
        $this->assertNull($battle->min_duration_seconds);

        $qualificationCharge = FestivalChargeDefinition::query()
            ->where('festival_edition_id', $edition->id)
            ->where('kind', 'qualification')
            ->whereNull('festival_category_id')
            ->firstOrFail();
        $this->assertSame(50000, $qualificationCharge->amount_cents);
        $ordinarySolo = $edition->categories()->where('code', 'amateurs')->firstOrFail();
        $this->assertSame(290000, FestivalChargeDefinition::query()->where('festival_category_id', $ordinarySolo->id)->value('amount_cents'));
        $this->assertSame(180000, FestivalChargeDefinition::query()->where('festival_category_id', $battle->id)->value('amount_cents'));

        $masters = $edition->categories()->where('code', 'masters-profi-35')->firstOrFail();
        $this->assertSame(35, $masters->min_age);
        $this->assertSame(180, $masters->min_duration_seconds);
        $this->assertSame(240, $masters->max_duration_seconds);

        $duet = $edition->categories()->where('code', 'profi-exotic-duets')->firstOrFail();
        $duetCharge = FestivalChargeDefinition::query()->where('festival_category_id', $duet->id)->firstOrFail();
        $this->assertSame(320000, $duetCharge->amount_cents);
        $this->assertSame(2, $duetCharge->included_members);
        $this->assertSame(40000, $duetCharge->additional_member_amount_cents);
        $this->assertSame(5, $duetCharge->due_days_after_approval);
        $this->assertSame('2026-10-18 23:59:59', $duetCharge->due_hard_cap_at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));

        $music = $edition->festivalRequirementDefinitions()->where('code', 'performance_music')->firstOrFail();
        $this->assertSame('2026-11-17 23:59:59', $music->due_at->timezone('Europe/Kyiv')->format('Y-m-d H:i:s'));
    }

    public function test_import_maps_protocol_maxima_and_omits_hot_exot_deductions(): void
    {
        [$account, $edition] = $this->targetEdition();
        app(SyncCharmExoticFestival2026::class)->execute($account->id, $edition->id);

        $expectedMaxima = [
            'amateurs' => 95,
            'semi-professional' => 95,
            'profi-exotic-technique' => 105,
            'profi-exotic-musique-soul' => 100,
            'profi-exotic-art' => 105,
            'profi-exotic-duets' => 100,
            'hot-exot' => 80,
            'masters-amateurs-35' => 95,
            'masters-semi-pro-35' => 95,
            'masters-profi-35' => 95,
            'elite' => 130,
        ];

        foreach ($expectedMaxima as $categoryCode => $expectedMaximum) {
            $category = FestivalCategory::query()->where('festival_edition_id', $edition->id)->where('code', $categoryCode)->firstOrFail();
            $rubric = FestivalRubric::query()->where('festival_category_id', $category->id)->with('sections.criteria')->firstOrFail();
            $awardMaximum = $rubric->sections
                ->where('contribution.value', 'award')
                ->flatMap->criteria
                ->sum(fn ($criterion): float => (float) $criterion->max_score);

            $this->assertSame((float) $expectedMaximum, $awardMaximum, $categoryCode);
            $this->assertSame($categoryCode === 'hot-exot' ? 0 : 1, $rubric->sections->where('contribution.value', 'deduction')->count(), $categoryCode);
        }
    }

    public function test_import_can_preserve_an_existing_production_festival_identity(): void
    {
        [$account, $edition] = $this->targetEdition();
        $edition->series->update([
            'name' => 'Charm Exotic Fest',
            'slug' => 'charm-exotic-fest',
            'organizer_email' => 'existing@example.com',
        ]);
        $edition->update([
            'title' => 'Charm Exotic Fest "Velvet Night" Autumn 2026',
            'slug' => 'charm-exotic-fest-velvet-night-autumn-2026',
            'starts_at' => '2026-11-29 10:00:00',
            'ends_at' => '2026-11-29 19:00:00',
            'registration_opens_at' => '2026-08-28 05:00:00',
        ]);
        $direction = $edition->directions()->create([
            'account_id' => $account->id,
            'code' => 'exotic-pole-dance',
            'name' => 'Exotic Pole Dance',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $existingCategoryIds = [];
        foreach ([
            'amateurs',
            'semi-professional',
            'profi-exotic-technique',
            'profi-exotic-musique-soul',
            'profi-exotic-art',
            'profi-exotic-duets',
            'hot-exot',
            'masters-amateurs-35',
            'masters-semi-pro-35',
            'masters-profi-35',
            'elite',
        ] as $categoryCode) {
            $category = FestivalCategory::factory()->for($edition)->create([
                'account_id' => $account->id,
                'festival_direction_id' => $direction->id,
                'festival_workflow_id' => null,
                'code' => $categoryCode,
                'name' => $categoryCode,
            ]);
            $existingCategoryIds[$categoryCode] = $category->id;
        }

        $result = app(SyncCharmExoticFestival2026::class)->execute($account->id, $edition->id, true, true);
        $edition = $edition->fresh();

        $this->assertSame('Charm Exotic Fest "Velvet Night" Autumn 2026', $edition->title);
        $this->assertSame('charm-exotic-fest-velvet-night-autumn-2026', $edition->slug);
        $this->assertSame('2026-11-29 10:00:00', $edition->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-29 19:00:00', $edition->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-28 05:00:00', $edition->registration_opens_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Charm Exotic Fest', $edition->series->name);
        $this->assertSame('existing@example.com', $edition->series->organizer_email);
        $this->assertSame('Exotic Pole Dance', $direction->fresh()->name);
        $this->assertSame('exotic-pole-dance', $direction->fresh()->code);
        foreach ($existingCategoryIds as $categoryCode => $categoryId) {
            $this->assertSame(
                $categoryId,
                $edition->categories()->where('code', $categoryCode)->value('id'),
                $categoryCode,
            );
        }
        $this->assertNotContains(
            $edition->categories()->where('code', 'exotic-battles')->value('id'),
            $existingCategoryIds,
        );
        $this->assertSame(12, $result['after']['categories']);
        $this->assertSame(11, $result['after']['rubrics']);
        $this->assertSame(FestivalEditionStatus::Draft, $edition->status);
        $this->assertSame(FestivalRegistrationStatus::Closed, $edition->registration_status);
    }

    public function test_import_refuses_a_wrong_target_or_an_edition_with_runtime_data(): void
    {
        [$account, $edition] = $this->targetEdition();
        $sync = app(SyncCharmExoticFestival2026::class);

        try {
            $sync->preview($account->id + 1, $edition->id);
            $this->fail('A mismatched account ID must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Charmpole', $exception->getMessage());
        }

        $edition->entries()->create([
            'account_id' => $account->id,
            'festival_portal_user_id' => FestivalPortalUser::factory()->for($account)->create()->id,
            'festival_category_id' => FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id])->id,
            'code' => 'CHF-RUNTIME-GUARD',
            'entry_name' => 'Existing entry',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to repurpose');
        $sync->preview($account->id, $edition->id);
    }

    public function test_production_execution_requires_the_explicit_confirmation_flag(): void
    {
        [$account, $edition] = $this->targetEdition();
        App::detectEnvironment(fn (): string => 'production');

        try {
            app(SyncCharmExoticFestival2026::class)->execute($account->id, $edition->id);
            $this->fail('Production synchronization must require explicit confirmation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('explicit confirmation', $exception->getMessage());
        }
    }

    public function test_production_execution_requires_existing_identity_preservation(): void
    {
        [$account, $edition] = $this->targetEdition();
        App::detectEnvironment(fn (): string => 'production');

        try {
            app(SyncCharmExoticFestival2026::class)->execute($account->id, $edition->id, true, false);
            $this->fail('Production synchronization must preserve the existing Festival identity.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preservation', $exception->getMessage());
        }
    }

    public function test_import_refuses_an_edition_with_admission_configuration(): void
    {
        [$account, $edition] = $this->targetEdition();
        $edition->admissionTypes()->create([
            'account_id' => $account->id,
            'name' => 'Existing admission',
            'inventory' => 100,
            'price_cents' => 10000,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to repurpose');

        app(SyncCharmExoticFestival2026::class)->preview($account->id, $edition->id);
    }

    /** @return array{Account, FestivalEdition} */
    private function targetEdition(): array
    {
        $account = Account::factory()->create([
            'slug' => 'charmpole',
            'name' => 'Charmpole',
            'enable_festivals' => true,
            'timezone' => 'Europe/Kyiv',
        ]);
        $series = FestivalSeries::factory()->for($account)->create([
            'name' => 'Ladna Festival Series',
            'slug' => 'ladna-festival-showcase',
        ]);
        $edition = FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Ladna Pole & Aerial Festival 2027',
            'slug' => 'ladna-festival-showcase-2027',
            'status' => FestivalEditionStatus::Published,
            'registration_status' => FestivalRegistrationStatus::Open,
        ]);

        return [$account, $edition];
    }
}
