<?php

namespace App\Actions\Festivals;

use App\Enums\FestivalChargeDuePolicy;
use App\Enums\FestivalChargePricingMode;
use App\Enums\FestivalCompetitionFormat;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalRegistrationStatus;
use App\Enums\FestivalRequirementInputType;
use App\Enums\FestivalRequirementType;
use App\Enums\FestivalRubricSectionContribution;
use App\Enums\FestivalWorkflowReviewEffect;
use App\Enums\FestivalWorkflowReviewMode;
use App\Enums\FestivalWorkflowStepType;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalChargeDefinition;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalRubric;
use App\Models\FestivalSeries;
use App\Models\FestivalWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncCharmExoticFestival2026
{
    private const AccountSlug = 'charmpole';

    private const EditionTitle = 'Charm Exotic Pole Dance Fest — Autumn 2026';

    /**
     * @return array<string, int|string|bool|array<string, int>>
     */
    public function preview(int $expectedAccountId, int $expectedEditionId, bool $preserveExistingIdentity = false): array
    {
        [$account, $edition] = $this->validatedTarget($expectedAccountId, $expectedEditionId);

        return [
            'account_id' => $account->id,
            'account_slug' => $account->slug,
            'edition_id' => $edition->id,
            'current_title' => $edition->title,
            'target_title' => $preserveExistingIdentity ? $edition->title : self::EditionTitle,
            'identity_preserved' => $preserveExistingIdentity,
            'category_count' => count($this->categories()),
            'rubric_count' => count($this->rubricCategoryCodes()),
            'online_payment_ready' => $this->onlinePaymentReady($account),
            'current_counts' => $this->resourceCounts($edition),
        ];
    }

    /**
     * @return array{edition: FestivalEdition, online_payment_ready: bool, before: array<string, int>, after: array<string, int>}
     */
    public function execute(
        int $expectedAccountId,
        int $expectedEditionId,
        bool $allowProduction = false,
        bool $preserveExistingIdentity = false,
    ): array {
        if (app()->environment('production') && ! $allowProduction) {
            throw new RuntimeException('Production synchronization requires the explicit confirmation flag.');
        }

        if (! app()->environment(['local', 'testing', 'production'])) {
            throw new RuntimeException('This guarded importer may run only in local, testing, or explicitly confirmed production environments.');
        }

        return DB::transaction(function () use ($expectedAccountId, $expectedEditionId, $preserveExistingIdentity): array {
            [$account, $edition] = $this->validatedTarget($expectedAccountId, $expectedEditionId, true);
            $before = $this->resourceCounts($edition);
            $this->synchronize($account, $edition, $preserveExistingIdentity);
            $edition = $edition->fresh();

            return [
                'edition' => $edition,
                'online_payment_ready' => $this->onlinePaymentReady($account),
                'before' => $before,
                'after' => $this->resourceCounts($edition),
            ];
        }, 3);
    }

    /** @return array{Account, FestivalEdition} */
    private function validatedTarget(int $expectedAccountId, int $expectedEditionId, bool $lock = false): array
    {
        if ($expectedAccountId < 1 || $expectedEditionId < 1) {
            throw new RuntimeException('Positive exact account and edition IDs are required.');
        }

        $accountQuery = Account::query()->where('slug', self::AccountSlug);
        if ($lock) {
            $accountQuery->lockForUpdate();
        }
        $account = $accountQuery->first();

        if (! $account || $account->id !== $expectedAccountId) {
            throw new RuntimeException('The expected account does not match the Charmpole account.');
        }

        $editionQuery = FestivalEdition::query()->whereBelongsTo($account)->whereKey($expectedEditionId);
        if ($lock) {
            $editionQuery->lockForUpdate();
        }
        $edition = $editionQuery->first();

        if (! $edition) {
            throw new RuntimeException('The expected Festival edition does not belong to Charmpole.');
        }

        if (! in_array($edition->title, [
            'Ladna Pole & Aerial Festival 2027',
            'Charm Exotic Fest "Velvet Night" Autumn 2026',
            self::EditionTitle,
        ], true)) {
            throw new RuntimeException('The target edition title is not the empty showcase or the managed Charm Exotic edition.');
        }

        $runtimeCounts = $this->runtimeCounts($edition);
        if (collect($runtimeCounts)->contains(fn (int $count): bool => $count > 0)) {
            throw new RuntimeException('Refusing to repurpose a Festival edition that contains entries, schedules, judging, payments, admission, or results.');
        }

        return [$account, $edition];
    }

    private function synchronize(Account $account, FestivalEdition $edition, bool $preserveExistingIdentity): void
    {
        $this->synchronizeSeriesAndEdition($account, $edition, $preserveExistingIdentity);
        $this->synchronizeStage($account, $edition);
        $direction = $this->synchronizeDirection($account, $edition, $preserveExistingIdentity);
        [$standardWorkflow, $battleWorkflow] = $this->synchronizeWorkflows($account, $edition);
        $categories = $this->synchronizeCategories($account, $edition, $direction->id, $standardWorkflow, $battleWorkflow);
        $this->synchronizeRequirements($account, $edition, $standardWorkflow, $battleWorkflow);
        $this->synchronizeCharges($account, $edition, $categories, $standardWorkflow, $battleWorkflow);
        $this->synchronizeRubrics($account, $edition, $categories);
        $this->synchronizeContent($account, $edition);
        $this->removePlaceholderConfiguration($edition);
    }

    private function synchronizeSeriesAndEdition(Account $account, FestivalEdition $edition, bool $preserveExistingIdentity): void
    {
        $series = FestivalSeries::query()
            ->where('account_id', $account->id)
            ->whereKey($edition->festival_series_id)
            ->lockForUpdate()
            ->firstOrFail();
        $seriesAttributes = [
            'name' => 'Charm Exotic Pole Dance Fest',
            'slug' => 'charm-exotic-pole-dance-fest',
            'summary' => 'Фестиваль краси, сили й творчості у напрямку Exotic Pole Dance.',
            'organizer_name' => 'Тімофєєва Анастасія, студія Charm',
            'organizer_email' => 'charmepoledance@gmail.com',
            'organizer_phone' => '+380969597567',
            'organizer_telegram_url' => 'https://t.me/charmpole',
            'organizer_instagram_url' => 'https://instagram.com/charm_exotic_pole_dance_fest',
            'is_active' => true,
        ];
        $series->update($preserveExistingIdentity ? ['is_active' => true] : $seriesAttributes);

        $startsAt = CarbonImmutable::create(2026, 11, 29, 0, 0, 0, 'Europe/Kyiv')->utc();
        $endsAt = CarbonImmutable::create(2026, 11, 29, 23, 59, 59, 'Europe/Kyiv')->utc();
        $editionAttributes = [
            'festival_series_id' => $series->id,
            'slug' => 'charm-exotic-pole-dance-fest-autumn-2026',
            'title' => self::EditionTitle,
            'status' => FestivalEditionStatus::Draft,
            'registration_status' => FestivalRegistrationStatus::Closed,
            'summary' => 'Charm Exotic Pole Dance Fest, Київ, 29 листопада 2026 року.',
            'description_html' => '<p>Свято краси, сили й творчості та сцена для вільного самовираження в Exotic Pole Dance.</p>',
            'rules_html' => $this->rulesHtml(),
            'venue_name' => 'Caribbean Club',
            'venue_address' => 'Київ, вул. Симона Петлюри, 4',
            'venue_map_url' => null,
            'venue_directions' => 'Детальний час прибуття та таймінг буде оприлюднено не пізніше ніж за два тижні до фестивалю.',
            'timezone' => 'Europe/Kyiv',
            'currency' => 'UAH',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'age_reference_date' => '2026-11-29',
            'registration_opens_at' => null,
            'registration_closes_at' => $this->kyivDeadline(2026, 10, 10),
            'published_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'archived_at' => null,
        ];
        if ($preserveExistingIdentity) {
            unset(
                $editionAttributes['slug'],
                $editionAttributes['title'],
                $editionAttributes['starts_at'],
                $editionAttributes['ends_at'],
                $editionAttributes['registration_opens_at'],
            );
        }
        $edition->update($editionAttributes);
    }

    private function synchronizeStage(Account $account, FestivalEdition $edition): void
    {
        $stage = $edition->stages()->where('name', 'Основна сцена')->first()
            ?? $edition->stages()->orderBy('id')->first();
        $attributes = [
            'account_id' => $account->id,
            'name' => 'Основна сцена',
            'description' => 'Сцена 4,3 м; один пілон висотою 4 м і діаметром 42 мм зі статичним та динамічним режимами. Позаду — екран 1920×1080, відстань від пілона до екрана 2 м. Покриття підлоги — лінолеум.',
            'is_active' => true,
            'sort_order' => 10,
        ];
        $stage ? $stage->update($attributes) : $edition->stages()->create($attributes);
        $edition->stages()->where('id', '!=', $stage?->id ?? $edition->stages()->where('name', 'Основна сцена')->value('id'))->update(['is_active' => false]);
    }

    private function synchronizeDirection(Account $account, FestivalEdition $edition, bool $preserveExistingIdentity): FestivalDirection
    {
        $direction = $edition->directions()->where('code', 'pole-exotic')->first()
            ?? $edition->directions()->orderBy('id')->first();
        $attributes = ['account_id' => $account->id, 'is_active' => true, 'sort_order' => 10];
        if (! $preserveExistingIdentity) {
            $attributes += ['code' => 'pole-exotic', 'name' => 'Pole Exotic'];
        }
        $direction ? $direction->update($attributes) : $direction = $edition->directions()->create($attributes);
        $edition->directions()->whereKeyNot($direction->id)->update(['is_active' => false]);

        return $direction->fresh();
    }

    /** @return array{FestivalWorkflow, FestivalWorkflow} */
    private function synchronizeWorkflows(Account $account, FestivalEdition $edition): array
    {
        $standard = $edition->workflows()->where('name', 'Стандартна реєстрація')->first()
            ?? $edition->workflows()->orderBy('id')->first();
        $standardAttributes = ['account_id' => $account->id, 'name' => 'Стандартна реєстрація', 'is_active' => true, 'sort_order' => 10];
        $standard ? $standard->update($standardAttributes) : $standard = $edition->workflows()->create($standardAttributes);

        $battle = $edition->workflows()->whereIn('name', ['Реєстрація Exotic Battles', 'Перевірка організатором'])->whereKeyNot($standard->id)->first()
            ?? $edition->workflows()->whereKeyNot($standard->id)->orderBy('id')->first();
        $battleAttributes = ['account_id' => $account->id, 'name' => 'Реєстрація Exotic Battles', 'is_active' => true, 'sort_order' => 20];
        $battle ? $battle->update($battleAttributes) : $battle = $edition->workflows()->create($battleAttributes);

        $this->synchronizeWorkflowSteps($standard, true);
        $this->synchronizeWorkflowSteps($battle, false);
        $edition->workflows()->whereNotIn('id', [$standard->id, $battle->id])->update(['is_active' => false]);

        return [$standard->fresh(), $battle->fresh()];
    }

    private function synchronizeWorkflowSteps(FestivalWorkflow $workflow, bool $qualification): void
    {
        $steps = [
            'application' => [FestivalWorkflowStepType::Application, $qualification ? 'Заявка та відеовідбір' : 'Заявка на Exotic Battles', FestivalWorkflowReviewMode::Organizer, $qualification ? FestivalWorkflowReviewEffect::Qualification : FestivalWorkflowReviewEffect::None, 10, $this->kyivDeadline(2026, 10, 10)],
            'participation_payment' => [FestivalWorkflowStepType::Payment, 'Оплата участі', FestivalWorkflowReviewMode::Automatic, FestivalWorkflowReviewEffect::None, 20, null],
            'technical_form' => [FestivalWorkflowStepType::Form, 'Музика та технічні деталі', FestivalWorkflowReviewMode::Organizer, FestivalWorkflowReviewEffect::None, 30, $this->kyivDeadline(2026, 11, 17)],
            'summary' => [FestivalWorkflowStepType::Summary, 'Підсумок', FestivalWorkflowReviewMode::Automatic, FestivalWorkflowReviewEffect::None, 40, null],
        ];

        foreach ($steps as $code => [$type, $title, $reviewMode, $reviewEffect, $sortOrder, $dueAt]) {
            $step = $workflow->steps()->where('code', $code)->first();
            $active = $qualification || $code !== 'technical_form';
            $attributes = [
                'account_id' => $workflow->account_id,
                'code' => $code,
                'type' => $type,
                'title' => $title,
                'description' => null,
                'sort_order' => $sortOrder,
                'review_mode' => $reviewMode,
                'review_effect' => $reviewEffect,
                'opens_at' => null,
                'due_at' => $dueAt,
                'config' => null,
                'is_active' => $active,
            ];
            $step ? $step->update($attributes) : $workflow->steps()->create($attributes);
        }
    }

    private function kyivDeadline(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, 23, 59, 59, 'Europe/Kyiv')->utc();
    }

    private function onlinePaymentReady(Account $account): bool
    {
        return DB::table('integration_settings')
            ->where('account_id', $account->id)
            ->where('provider', 'monopay')
            ->where('is_enabled', true)
            ->whereNotNull('credentials')
            ->exists();
    }

    /** @return array<string, int> */
    private function runtimeCounts(FestivalEdition $edition): array
    {
        return [
            'entries' => DB::table('festival_entries')->where('festival_edition_id', $edition->id)->count(),
            'schedule_slots' => DB::table('festival_schedule_slots')->where('festival_edition_id', $edition->id)->count(),
            'judge_assignments' => DB::table('festival_judge_assignments')->where('festival_edition_id', $edition->id)->count(),
            'score_sheets' => DB::table('festival_score_sheets')->whereIn('festival_entry_id', DB::table('festival_entries')->select('id')->where('festival_edition_id', $edition->id))->count(),
            'results' => DB::table('festival_results')->where('festival_edition_id', $edition->id)->count(),
            'charges' => DB::table('festival_charges')->whereIn('festival_entry_id', DB::table('festival_entries')->select('id')->where('festival_edition_id', $edition->id))->count(),
            'ticket_orders' => DB::table('festival_ticket_orders')->where('festival_edition_id', $edition->id)->count(),
            'admission_types' => DB::table('festival_admission_types')->where('festival_edition_id', $edition->id)->count(),
        ];
    }

    /** @return array<string, int> */
    private function resourceCounts(FestivalEdition $edition): array
    {
        return [
            'directions' => $edition->directions()->count(),
            'categories' => $edition->categories()->count(),
            'workflows' => $edition->workflows()->count(),
            'requirements' => FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->count(),
            'fees' => FestivalChargeDefinition::query()->where('festival_edition_id', $edition->id)->count(),
            'rubrics' => FestivalRubric::query()->where('festival_edition_id', $edition->id)->count(),
            'stages' => $edition->stages()->count(),
            'content_sections' => $edition->sections()->count(),
            'admission_types' => $edition->admissionTypes()->count(),
        ];
    }

    private function removePlaceholderConfiguration(FestivalEdition $edition): void
    {
        FestivalRequirementDefinition::query()
            ->where('festival_edition_id', $edition->id)
            ->where('is_active', false)
            ->delete();
        FestivalChargeDefinition::query()
            ->where('festival_edition_id', $edition->id)
            ->where('is_active', false)
            ->delete();
        FestivalRubric::query()
            ->where('festival_edition_id', $edition->id)
            ->where('is_active', false)
            ->delete();
        $edition->categories()->where('is_active', false)->delete();
        $edition->workflows()->where('is_active', false)->delete();
        $edition->directions()->where('is_active', false)->delete();
        $edition->stages()->where('is_active', false)->delete();
        $edition->sections()->where('is_active', false)->delete();
        $edition->admissionTypes()->delete();
    }

    /**
     * @return array<string, FestivalCategory>
     */
    private function synchronizeCategories(Account $account, FestivalEdition $edition, int $directionId, FestivalWorkflow $standardWorkflow, FestivalWorkflow $battleWorkflow): array
    {
        $models = [];

        foreach ($this->categories() as $index => $data) {
            $code = $data['code'];
            $category = $edition->categories()->where('code', $code)->first() ?? new FestivalCategory;
            $category->fill([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_workflow_id' => $data['competition_format'] === FestivalCompetitionFormat::Knockout->value ? $battleWorkflow->id : $standardWorkflow->id,
                'festival_direction_id' => $directionId,
                'code' => $code,
                'name' => $data['name'],
                'min_members' => $data['min_members'],
                'max_members' => $data['max_members'],
                'min_age' => $data['min_age'],
                'max_age' => null,
                'min_duration_seconds' => $data['min_duration_seconds'],
                'max_duration_seconds' => $data['max_duration_seconds'],
                'registration_closes_at' => $this->kyivDeadline(2026, 10, 10),
                'requirements_html' => $data['requirements_html'],
                'competition_format' => $data['competition_format'],
                'minimum_entries_to_run' => 5,
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
            ]);
            $category->save();
            $models[$code] = $category;
        }

        $edition->categories()->whereNotIn('code', array_keys($models))->update(['is_active' => false]);

        return $models;
    }

    /**
     * @return array<int, array{code: string, name: string, min_members: int, max_members: int, min_age: int, min_duration_seconds: int|null, max_duration_seconds: int|null, competition_format: string, requirements_html: string}>
     */
    private function categories(): array
    {
        $scored = FestivalCompetitionFormat::Scored->value;

        return [
            ['code' => 'amateurs', 'name' => 'Amateurs', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => 150, 'max_duration_seconds' => 195, 'competition_format' => $scored, 'requirements_html' => '<p>Для учасниць із невеликим досвідом сценічних виступів або без нього. Пілон/партер — 30/70. Помічники дозволені за умови наявності вхідного квитка та без виконання трюків на пілоні.</p>'],
            ['code' => 'semi-professional', 'name' => 'Semi-professional', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => 165, 'max_duration_seconds' => 195, 'competition_format' => $scored, 'requirements_html' => '<p>Для досвідченіших учасниць або тренерок-початківиць. Пілон/партер — 30/70; щонайменше один раз використати 70% висоти пілона. Помічники дозволені з попереднім погодженням.</p>'],
            ['code' => 'profi-exotic-technique', 'name' => 'Profi Exotic Technique', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => 180, 'max_duration_seconds' => 240, 'competition_format' => $scored, 'requirements_html' => '<p>Трюки й трюкові зв’язки на пілоні, акробатичні та танцювальні елементи в партері. Пілон/партер — 70/30; використати 70% висоти. Помічники та декорації не допускаються.</p>'],
            ['code' => 'profi-exotic-musique-soul', 'name' => 'Profi Exotic Musique Soul', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => 180, 'max_duration_seconds' => 240, 'competition_format' => $scored, 'requirements_html' => '<p>Класична екзотна партерна та навколопілонна хореографія з акцентом на музичність. Пілон/партер — 30/70; використати 70% висоти. Помічники та декорації не допускаються.</p>'],
            ['code' => 'profi-exotic-art', 'name' => 'Profi Exotic Art', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => 180, 'max_duration_seconds' => 240, 'competition_format' => $scored, 'requirements_html' => '<p>Обов’язкові сценічний образ і сюжет. Співвідношення пілон/партер — на розсуд учасниці; використати 70% висоти. Помічники та декорації вітаються за погодженням.</p>'],
            ['code' => 'profi-exotic-duets', 'name' => 'Profi Exotic Duets', 'min_members' => 2, 'max_members' => 100, 'min_age' => 18, 'min_duration_seconds' => 180, 'max_duration_seconds' => 270, 'competition_format' => $scored, 'requirements_html' => '<p>Дует або група створює одну цілісну сценічну роботу навколо одного пілона. Обов’язкові взаємодія та синхронність. Пілон/партер — 30/70; використати 70% висоти.</p>'],
            ['code' => 'hot-exot', 'name' => 'HOT EXOT', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => 160, 'max_duration_seconds' => 220, 'competition_format' => $scored, 'requirements_html' => '<p>Експериментальна категорія з чітко вираженими Strip або Strip Heels: щонайменше 60% хореографії та 40% роботи на пілоні. Протокол не передбачає штрафних балів.</p>'],
            ['code' => 'masters-amateurs-35', 'name' => 'Masters Amateurs (35+)', 'min_members' => 1, 'max_members' => 1, 'min_age' => 35, 'min_duration_seconds' => 165, 'max_duration_seconds' => 195, 'competition_format' => $scored, 'requirements_html' => '<p>Умови Amateurs для учасниць віком від 35 років. Пілон/партер — 30/70.</p>'],
            ['code' => 'masters-semi-pro-35', 'name' => 'Masters Semi-Pro (35+)', 'min_members' => 1, 'max_members' => 1, 'min_age' => 35, 'min_duration_seconds' => 165, 'max_duration_seconds' => 195, 'competition_format' => $scored, 'requirements_html' => '<p>Категорія 35+ з досвідом виступів. Пілон/партер — 30/70; щонайменше один раз використати 70% висоти пілона.</p>'],
            ['code' => 'masters-profi-35', 'name' => 'Masters Profi (35+)', 'min_members' => 1, 'max_members' => 1, 'min_age' => 35, 'min_duration_seconds' => 180, 'max_duration_seconds' => 240, 'competition_format' => $scored, 'requirements_html' => '<p>Професійна категорія 35+. Пілон/партер — 40/60; напрям на вибір учасниці; використати 70% висоти пілона.</p>'],
            ['code' => 'elite', 'name' => 'Elite', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => 160, 'max_duration_seconds' => 300, 'competition_format' => $scored, 'requirements_html' => '<p>Для переможниць Profi/Elite, тренерок і презентерок. Пілон, партер, реквізит, спецефекти та поєднання стилів — на розсуд виконавиці.</p>'],
            ['code' => 'exotic-battles', 'name' => 'Exotic Battles', 'min_members' => 1, 'max_members' => 1, 'min_age' => 18, 'min_duration_seconds' => null, 'max_duration_seconds' => null, 'competition_format' => FestivalCompetitionFormat::Knockout->value, 'requirements_html' => '<p>Добровільний батловий формат для будь-якого рівня. Відеовідбір не потрібен. Пари танцюють почергово під один трек; результат кожного батлу на 50% визначають чотири судді та на 50% — голоси глядачів.</p>'],
        ];
    }

    private function synchronizeRequirements(Account $account, FestivalEdition $edition, FestivalWorkflow $standardWorkflow, FestivalWorkflow $battleWorkflow): void
    {
        FestivalRequirementDefinition::query()->where('festival_edition_id', $edition->id)->update(['is_active' => false]);

        $standardApplication = $standardWorkflow->steps()->where('code', 'application')->firstOrFail();
        $standardTechnical = $standardWorkflow->steps()->where('code', 'technical_form')->firstOrFail();
        $battleApplication = $battleWorkflow->steps()->where('code', 'application')->firstOrFail();

        $definitions = [
            [$standardApplication->id, 'qualification_video_url', FestivalRequirementType::QualificationVideo, FestivalRequirementInputType::Url, 'Посилання на відео для відбору', 'Невідредаговане відео тривалістю 1 хв 12 с — 2 хв на YouTube або Instagram. Сторінка має бути доступною організаторам.', ['allowed_hosts' => ['youtube.com', 'www.youtube.com', 'youtu.be', 'instagram.com', 'www.instagram.com']], null],
            [$standardApplication->id, 'qualification_video_confirmation', FestivalRequirementType::Waiver, FestivalRequirementInputType::Boolean, 'Підтвердження відео', 'Підтверджую, що відео без монтажу, зняте не раніше ніж за шість місяців до подання заявки та відображає мій поточний рівень.', ['accepted' => true], null],
            [$standardApplication->id, 'planned_performance', FestivalRequirementType::CustomDocument, FestivalRequirementInputType::LongText, 'Майбутня постановка', 'Коротко опишіть образ, ідею постановки та заплановане сценічне рішення.', null, null],
            [$standardApplication->id, 'props_helpers', FestivalRequirementType::CustomDocument, FestivalRequirementInputType::LongText, 'Реквізит, декорації та помічники', 'Опишіть реквізит, декорації, спецефекти й помічників або напишіть «Немає». Усе зазначене потребує погодження організатора.', null, null],
            [$standardApplication->id, 'rules_media_consent', FestivalRequirementType::Waiver, FestivalRequirementInputType::Boolean, 'Згода з правилами та використанням фото/відео', 'Підтверджую ознайомлення з правилами та згоду на передбачене ними використання фото й відео.', ['accepted' => true], null],
            [$standardApplication->id, 'identity_insurance_confirmation', FestivalRequirementType::Insurance, FestivalRequirementInputType::Boolean, 'Документ і спортивне страхування', 'Підтверджую, що матиму документ, який посвідчує особу, та чинне спортивне страхування на день фестивалю.', ['accepted' => true], null],
            [$standardTechnical->id, 'music_artist', FestivalRequirementType::CustomDocument, FestivalRequirementInputType::ShortText, 'Виконавець музичного треку', 'Вкажіть оригінального виконавця. Поєднання виконавця й назви треку має бути унікальним у категорії.', null, null],
            [$standardTechnical->id, 'music_title', FestivalRequirementType::CustomDocument, FestivalRequirementInputType::ShortText, 'Оригінальна назва музичного треку', 'Першою бронюється композиція, для якої учасниця першою повністю подала цей крок.', null, null],
            [$standardTechnical->id, 'performance_music', FestivalRequirementType::Music, FestivalRequirementInputType::File, 'Музика для виступу', 'Завантажте MP3. Тривалість автоматично перевіряється за межами обраної категорії.', null, ['extensions' => ['mp3'], 'mimes' => ['audio/mpeg'], 'size' => 51200]],
            [$standardTechnical->id, 'music_language_confirmation', FestivalRequirementType::Waiver, FestivalRequirementInputType::Boolean, 'Підтвердження мови композиції', 'Підтверджую, що композиція не є російськомовною.', ['accepted' => true], null],
            [$battleApplication->id, 'battle_direction_confirmation', FestivalRequirementType::Waiver, FestivalRequirementInputType::Boolean, 'Підтвердження напряму Exotic Pole Dance', 'Підтверджую, що виступаю в напрямку Exotic Pole Dance та погоджуюся з батловим форматом.', ['accepted' => true], null],
            [$battleApplication->id, 'battle_rules_media_consent', FestivalRequirementType::Waiver, FestivalRequirementInputType::Boolean, 'Згода з правилами та використанням фото/відео', 'Підтверджую ознайомлення з правилами та згоду на передбачене ними використання фото й відео.', ['accepted' => true], null],
            [$battleApplication->id, 'battle_identity_insurance_confirmation', FestivalRequirementType::Insurance, FestivalRequirementInputType::Boolean, 'Документ і спортивне страхування', 'Підтверджую, що матиму документ, який посвідчує особу, та чинне спортивне страхування на день фестивалю.', ['accepted' => true], null],
        ];

        foreach ($definitions as $sortOrder => [$stepId, $code, $type, $inputType, $name, $instructions, $validation, $file]) {
            $definition = FestivalRequirementDefinition::query()
                ->where('festival_edition_id', $edition->id)
                ->whereNull('festival_category_id')
                ->where('code', $code)
                ->first() ?? new FestivalRequirementDefinition;
            $definition->fill([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_category_id' => null,
                'festival_workflow_step_id' => $stepId,
                'code' => $code,
                'type' => $type,
                'subject_scope' => 'entry',
                'input_type' => $inputType,
                'name' => $name,
                'instructions' => $instructions,
                'options' => null,
                'validation' => $validation,
                'pricing' => null,
                'stage' => 'final',
                'due_at' => $stepId === $standardTechnical->id ? $this->kyivDeadline(2026, 11, 17) : $this->kyivDeadline(2026, 10, 10),
                'allowed_extensions' => $file['extensions'] ?? null,
                'allowed_mime_types' => $file['mimes'] ?? null,
                'max_size_kb' => $file['size'] ?? 20480,
                'min_duration_seconds' => null,
                'max_duration_seconds' => null,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => ($sortOrder + 1) * 10,
            ]);
            $definition->save();
        }
    }

    /**
     * @param  array<string, FestivalCategory>  $categories
     */
    private function synchronizeCharges(Account $account, FestivalEdition $edition, array $categories, FestivalWorkflow $standardWorkflow, FestivalWorkflow $battleWorkflow): void
    {
        FestivalChargeDefinition::query()->where('festival_edition_id', $edition->id)->update(['is_active' => false]);
        $qualificationStep = $standardWorkflow->steps()->where('code', 'application')->firstOrFail();
        $participationStep = $standardWorkflow->steps()->where('code', 'participation_payment')->firstOrFail();
        $battlePaymentStep = $battleWorkflow->steps()->where('code', 'participation_payment')->firstOrFail();

        $this->synchronizeCharge($account, $edition, null, $qualificationStep->id, [
            'kind' => 'qualification',
            'name' => 'Відеовідбір',
            'amount_cents' => 50000,
            'pricing_mode' => FestivalChargePricingMode::Fixed,
            'due_at' => $this->kyivDeadline(2026, 10, 10),
            'due_policy' => FestivalChargeDuePolicy::Fixed,
            'sort_order' => 10,
        ]);

        foreach ($categories as $code => $category) {
            if ($code === 'exotic-battles') {
                $this->synchronizeCharge($account, $edition, $category, $battlePaymentStep->id, [
                    'kind' => 'participation',
                    'name' => 'Участь в Exotic Battles',
                    'amount_cents' => 180000,
                    'pricing_mode' => FestivalChargePricingMode::Fixed,
                    'due_at' => $this->kyivDeadline(2026, 10, 18),
                    'due_policy' => FestivalChargeDuePolicy::Fixed,
                    'sort_order' => 130,
                ]);

                continue;
            }

            $duet = $code === 'profi-exotic-duets';
            $this->synchronizeCharge($account, $edition, $category, $participationStep->id, [
                'kind' => 'participation',
                'name' => $duet ? 'Участь у Profi Exotic Duets' : 'Участь у фіналі',
                'amount_cents' => $duet ? 320000 : 290000,
                'pricing_mode' => $duet ? FestivalChargePricingMode::Roster : FestivalChargePricingMode::Fixed,
                'included_members' => $duet ? 2 : null,
                'additional_member_amount_cents' => $duet ? 40000 : null,
                'due_at' => null,
                'due_policy' => FestivalChargeDuePolicy::ApprovalRelative,
                'due_days_after_approval' => 5,
                'due_hard_cap_at' => $this->kyivDeadline(2026, 10, 18),
                'sort_order' => 20 + $category->sort_order,
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    private function synchronizeCharge(Account $account, FestivalEdition $edition, ?FestivalCategory $category, int $stepId, array $data): void
    {
        $definition = FestivalChargeDefinition::query()
            ->where('festival_edition_id', $edition->id)
            ->where('festival_category_id', $category?->id)
            ->where('festival_workflow_step_id', $stepId)
            ->where('kind', $data['kind'])
            ->first() ?? new FestivalChargeDefinition;
        $definition->fill([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category?->id,
            'festival_workflow_step_id' => $stepId,
            'currency' => 'UAH',
            'included_members' => null,
            'additional_member_amount_cents' => null,
            'due_days_after_approval' => null,
            'due_hard_cap_at' => null,
            ...$data,
            'is_active' => true,
        ]);
        $definition->save();
    }

    /** @param array<string, FestivalCategory> $categories */
    private function synchronizeRubrics(Account $account, FestivalEdition $edition, array $categories): void
    {
        FestivalRubric::query()->where('festival_edition_id', $edition->id)->update(['is_active' => false]);
        $templates = $this->rubricTemplates();

        foreach ($this->rubricCategoryCodes() as $sortIndex => $categoryCode) {
            $category = $categories[$categoryCode];
            $templateCode = match ($categoryCode) {
                'amateurs', 'semi-professional', 'masters-amateurs-35', 'masters-semi-pro-35', 'masters-profi-35' => 'amateur-semi-masters',
                'profi-exotic-technique' => 'profi-tricks',
                'profi-exotic-musique-soul' => 'profi-music-soul',
                'profi-exotic-art' => 'profi-art',
                'profi-exotic-duets' => 'profi-duet',
                'hot-exot' => 'hot-exot',
                'elite' => 'elite',
            };
            $rubric = FestivalRubric::query()
                ->where('festival_edition_id', $edition->id)
                ->where('festival_category_id', $category->id)
                ->first() ?? new FestivalRubric;
            $rubric->fill([
                'account_id' => $account->id,
                'festival_edition_id' => $edition->id,
                'festival_category_id' => $category->id,
                'name' => 'Протокол — '.$category->name,
                'is_active' => true,
                'sort_order' => ($sortIndex + 1) * 10,
            ]);
            $rubric->save();

            $sections = $templates[$templateCode];
            if ($templateCode !== 'hot-exot') {
                $sections['Штрафи'] = ['contribution' => FestivalRubricSectionContribution::Deduction->value, 'criteria' => $this->penaltyCriteria()];
            }
            $this->synchronizeRubricStructure($rubric, $sections);
        }
    }

    /**
     * @param  array<string, array{contribution?: string, criteria: array<string, int>}>  $sections
     */
    private function synchronizeRubricStructure(FestivalRubric $rubric, array $sections): void
    {
        $sectionIds = [];
        $sectionSortOrder = 0;
        foreach ($sections as $sectionName => $sectionData) {
            $section = $rubric->sections()->where('name', $sectionName)->first();
            $attributes = [
                'account_id' => $rubric->account_id,
                'name' => $sectionName,
                'weight' => 1,
                'contribution' => $sectionData['contribution'] ?? FestivalRubricSectionContribution::Award->value,
                'sort_order' => $sectionSortOrder,
            ];
            $section ? $section->update($attributes) : $section = $rubric->sections()->create($attributes);
            $sectionIds[] = $section->id;

            $criterionIds = [];
            $criterionSortOrder = 10;
            foreach ($sectionData['criteria'] as $criterionName => $maximumScore) {
                $criterion = $section->criteria()->where('name', $criterionName)->first();
                $criterionAttributes = [
                    'account_id' => $rubric->account_id,
                    'name' => $criterionName,
                    'max_score' => $maximumScore,
                    'weight' => 1,
                    'sort_order' => $criterionSortOrder,
                ];
                $criterion ? $criterion->update($criterionAttributes) : $criterion = $section->criteria()->create($criterionAttributes);
                $criterionIds[] = $criterion->id;
                $criterionSortOrder += 10;
            }
            $section->criteria()->whereNotIn('id', $criterionIds)->delete();
            $sectionSortOrder += 10;
        }
        $rubric->sections()->whereNotIn('id', $sectionIds)->delete();
    }

    /** @return array<int, string> */
    private function rubricCategoryCodes(): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, array<string, array{criteria: array<string, int>}>>
     */
    private function rubricTemplates(): array
    {
        return [
            'amateur-semi-masters' => [
                'Техніка' => ['criteria' => ['Технічність виконання' => 10, 'Складність і різноманітність' => 10, 'Складність і різноманітність партерної акробатики' => 5, 'Контроль тіла, точність виконання' => 5, 'Оригінальність елементів і зв’язок' => 5]],
                'Хореографія' => ['criteria' => ['Щільність' => 10, 'Амплітудність рухів' => 10, 'Музичність' => 5, 'Різноманітність і складність' => 5, 'Оригінальність' => 5]],
                'Артистизм' => ['criteria' => ['Цілісність постановки, художнє втілення' => 5, 'Емоційність та виразність виконання' => 5, 'Відповідність образу музиці' => 5, 'Оригінальність' => 5, 'Сексуальність подачі' => 5]],
            ],
            'profi-music-soul' => [
                'Техніка' => ['criteria' => ['Технічність виконання' => 10, 'Складність і різноманітність' => 5, 'Складність і різноманітність партерної акробатики' => 5, 'Контроль тіла, точність виконання' => 5, 'Оригінальність елементів і зв’язок' => 5]],
                'Хореографія' => ['criteria' => ['Щільність' => 10, 'Амплітудність рухів' => 5, 'Музичність' => 10, 'Різноманітність і складність' => 10, 'Оригінальність' => 5]],
                'Артистизм' => ['criteria' => ['Цілісність постановки, художнє втілення' => 10, 'Емоційність та виразність виконання' => 5, 'Відповідність образу музиці' => 5, 'Оригінальність' => 5, 'Сексуальність подачі' => 5]],
            ],
            'profi-art' => [
                'Техніка' => ['criteria' => ['Технічність виконання' => 10, 'Складність і різноманітність' => 10, 'Складність і різноманітність акробатики в партері' => 5, 'Контроль тіла, точність виконання' => 5, 'Оригінальність елементів і зв’язок' => 5]],
                'Хореографія' => ['criteria' => ['Щільність' => 5, 'Амплітудність рухів' => 5, 'Музичність' => 10, 'Різноманітність і складність' => 5, 'Оригінальність' => 5]],
                'Артистизм' => ['criteria' => ['Цілісність постановки, художнє втілення' => 10, 'Емоційність та виразність виконання' => 10, 'Ідея номера, відповідність втілення, артистизм' => 10, 'Оригінальність' => 5, 'Вплив на глядача' => 5]],
            ],
            'profi-tricks' => [
                'Техніка' => ['criteria' => ['Технічність виконання' => 10, 'Складність і різноманітність' => 10, 'Складність і різноманітність партерної акробатики' => 10, 'Контроль тіла, точність виконання' => 10, 'Оригінальність елементів і зв’язок' => 5]],
                'Хореографія' => ['criteria' => ['Щільність' => 10, 'Амплітудність рухів' => 10, 'Музичність' => 5, 'Різноманітність і складність' => 5, 'Оригінальність' => 5]],
                'Артистизм' => ['criteria' => ['Цілісність постановки, художнє втілення' => 5, 'Емоційність та виразність виконання' => 5, 'Відповідність образу музиці' => 5, 'Оригінальність' => 5, 'Сексуальність подачі' => 5]],
            ],
            'profi-duet' => [
                'Техніка' => ['criteria' => ['Технічність виконання' => 10, 'Складність і різноманітність' => 10, 'Складність і різноманітність акробатики в партері' => 5, 'Контроль тіла, точність виконання' => 5, 'Робота в парі' => 5]],
                'Хореографія' => ['criteria' => ['Щільність' => 10, 'Амплітудність рухів' => 10, 'Музичність' => 5, 'Різноманітність і складність' => 5, 'Робота в парі' => 5]],
                'Артистизм' => ['criteria' => ['Цілісність постановки, художнє втілення' => 10, 'Емоційність та виразність виконання' => 5, 'Ідея номера, відповідність втілення, артистизм' => 5, 'Костюм та візуальний ефект' => 5, 'Робота в парі' => 5]],
            ],
            'hot-exot' => [
                'Техніка' => ['criteria' => ['Розтяжка та гнучкість: шпагати, прогини, містки' => 5, 'Координаційні елементи: стрибки, зриви, складність' => 5, 'Балансові елементи: стійки, складність' => 5, 'Каскади переходів, складність' => 5, 'Ракурси' => 5, 'Загальна чистота виконання' => 5]],
                'Хореографія' => ['criteria' => ['Робота в різні шари музики' => 5, 'Виділення яскраво чутних акцентів' => 5, 'Відповідність рухів вокалу та музиці' => 5, 'Ізоляція та робота кожною частиною тіла' => 5, 'Жимність, чіткість, дотягнуті лінії' => 5, 'Загальна складність програми' => 5]],
                'Артистизм' => ['criteria' => ['Заповненість робочого простору' => 5, 'Міміка' => 5, 'Відповідність образу музиці' => 5, 'Утримання уваги аудиторії, взаємодія, атмосфера' => 5]],
            ],
            'elite' => [
                'Техніка' => ['criteria' => ['Технічність виконання' => 10, 'Складність і різноманітність' => 10, 'Складність і різноманітність акробатики в партері' => 10, 'Контроль тіла, точність виконання' => 10, 'Робота в парі' => 5]],
                'Хореографія' => ['criteria' => ['Щільність' => 10, 'Амплітудність рухів' => 10, 'Музичність' => 10, 'Різноманітність і складність' => 10, 'Відстежування власного стилю' => 5]],
                'Артистизм' => ['criteria' => ['Цілісність постановки, художнє втілення' => 10, 'Емоційність та виразність виконання' => 10, 'Відповідність образу музиці' => 10, 'Унікальність подачі' => 10]],
            ],
        ];
    }

    /** @return array<string, int> */
    private function penaltyCriteria(): array
    {
        return [
            'Несценічний вигляд, проблеми з костюмом та реквізитом' => 5,
            'Невідповідність музики образу або хореографії' => 5,
            'Нечисте виконання: спотикання, важкі входи та виходи' => 5,
            'Невідповідні ракурси' => 5,
            'Недотягнутість ліній: стопи, коліна' => 5,
            'Падіння' => 5,
            'Невиконання співвідношення пілон/партер' => 5,
            'Неповажне ставлення до суддів, глядачів або організаторів' => 5,
            'Початок або закінчення не в музику' => 5,
        ];
    }

    private function synchronizeContent(Account $account, FestivalEdition $edition): void
    {
        $sections = [
            'important-dates' => [
                'title' => 'Важливі дати',
                'body_html' => '<ul><li>Подання заявки — до 10 жовтня 2026 року.</li><li>Оплата фіналу — протягом п’яти днів після підтвердження відбору, але не пізніше 18 жовтня 2026 року.</li><li>Музика — до 17 листопада 2026 року.</li><li>Фестиваль — 29 листопада 2026 року; точний час буде повідомлено не пізніше ніж за два тижні.</li></ul>',
            ],
            'jury' => [
                'title' => 'Журі',
                'body_html' => '<ul><li>Саша Романова — артистизм.</li><li>Яна Іщенко — хореографія.</li><li>Іра BexIt — техніка.</li><li>Іра Патриляк — штрафи.</li></ul><p>Активні суддівські облікові записи буде призначено після отримання адрес електронної пошти.</p>',
            ],
            'stage' => [
                'title' => 'Сцена та музика',
                'body_html' => '<p>Основна сцена має один пілон висотою 4 м і діаметром 42 мм у статичному або динамічному режимі. Позаду пілона на відстані 2 м розташований екран 1920×1080. Музика подається у MP3 та має відповідати тривалості категорії. Російськомовні композиції не допускаються.</p>',
            ],
            'payments' => [
                'title' => 'Оплата участі',
                'body_html' => '<p>Оплата відбувається через захищену онлайн-сторінку Ladna: 500 грн за відеовідбір; 2 900 грн за звичайну сольну категорію; 3 200 грн за перших двох учасниць Duets і 400 грн за кожну наступну; 1 800 грн за Exotic Battles. Реєстрацію буде відкрито лише після підключення платіжної інтеграції.</p>',
            ],
        ];

        $sortOrder = 10;
        foreach ($sections as $key => $data) {
            $edition->sections()->updateOrCreate(
                ['key' => $key],
                [
                    'account_id' => $account->id,
                    'title' => $data['title'],
                    'kind' => 'rich_text',
                    'visibility' => 'public',
                    'body_html' => $data['body_html'],
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ],
            );
            $sortOrder += 10;
        }

        $edition->sections()->whereNotIn('key', array_keys($sections))->update(['is_active' => false]);
    }

    private function rulesHtml(): string
    {
        return <<<'HTML'
<h2>Загальні положення</h2>
<p>Charm Exotic Pole Dance Fest відбудеться 29 листопада 2026 року в Caribbean Club, Київ, вул. Симона Петлюри, 4. До участі допускаються особи від 18 років на момент подання заявки; для Masters — від 35 років.</p>
<p>Фестиваль заохочує авторські хореографічні та трюкові комбінації, сценічні костюми, макіяж, реквізит і декорації, якщо вони відповідають сюжету та попередньо погоджені.</p>
<h2>Відеовідбір і реєстрація</h2>
<p>Відеовідбір обов’язковий для всіх конкурсних категорій, крім Exotic Battles. Подайте до 10 жовтня 2026 року відкрите невідредаговане відео на YouTube або Instagram тривалістю від 1 хв 12 с до 2 хв. Відео має бути зняте не раніше ніж за шість місяців до заявки. Організатор може запропонувати іншу категорію.</p>
<p>Відеовідбір коштує 500 грн. Після підтвердження відбору оплатіть участь онлайн протягом п’яти днів, але не пізніше 18 жовтня 2026 року. Успішні внески не повертаються.</p>
<h2>Музика й технічні вимоги</h2>
<p>Завантажте MP3 до 17 листопада 2026 року. Назва й виконавець треку мають бути зазначені окремо. У межах однієї категорії композиція не повторюється та бронюється за першою повністю поданою заявкою. Російськомовні композиції заборонені. На фестиваль необхідно мати резервну копію музики.</p>
<h2>Костюм, безпека та поведінка</h2>
<p>Костюм має бути сценічним, відповідати образу, закривати інтимні зони та не містити реклами. Дозволені стрипи або ботфорти. Реквізит, помічники, живий реквізит і підтанцьовування обов’язково зазначаються в заявці та погоджуються.</p>
<p>Учасниця повинна мати документ, що посвідчує особу, та спортивне страхування. Заборонені алкогольне чи наркотичне сп’яніння, небезпечна поведінка, креми й лосьйони за добу до виступу та нанесення засобів на сам пілон. Судді можуть зупинити небезпечний виступ.</p>
<h2>Оцінювання</h2>
<p>Окремі судді оцінюють техніку, хореографію, артистизм і штрафи за чинними протоколами. HOT EXOT оцінюється без штрафного розділу. За рівності підсумкових балів остаточний порядок визначає суддівська колегія за загальним враженням.</p>
<h2>Exotic Battles</h2>
<p>Відеовідбір не потрібен. У кожному раунді дві учасниці почергово танцюють під один трек. Половину результату становить частка голосів чотирьох суддів, половину — частка голосів глядачів. Переможниця переходить до наступного раунду до визначення однієї переможниці.</p>
<h2>Фото та відео</h2>
<p>Подання заявки означає згоду з правилами використання організаторами матеріалів фестивальної фото- та відеозйомки для інформаційних, рекламних і комерційних матеріалів.</p>
HTML;
    }
}
