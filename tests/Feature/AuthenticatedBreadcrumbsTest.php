<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalDirection;
use App\Models\FestivalDocument;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\Location;
use App\Models\Trainer;
use App\Models\User;
use App\Support\Breadcrumbs\AppBreadcrumbs;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

class AuthenticatedBreadcrumbsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_location_edit_has_clickable_ancestors_and_a_plain_current_item(): void
    {
        $account = Account::factory()->create(['default_language' => 'en']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create(['name' => 'Podil studio']);

        $response = $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.locations.edit', [$account, $location]));

        $response->assertOk();
        $this->assertSame([
            ['label' => 'Workspace', 'href' => route('dashboard.index'), 'current' => false],
            ['label' => $account->name, 'href' => route('dashboard.accounts.show', $account), 'current' => false],
            ['label' => 'Locations', 'href' => route('dashboard.accounts.locations.index', $account), 'current' => false],
            ['label' => 'Edit: Podil studio', 'href' => null, 'current' => true],
        ], $this->breadcrumbItems($response));
    }

    public function test_festival_judging_and_deep_settings_trails_are_complete(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $direction = FestivalDirection::factory()->for($edition)->create(['account_id' => $account->id]);
        $category = FestivalCategory::factory()->for($edition)->for($direction)->create([
            'account_id' => $account->id,
            'name' => 'Junior silk',
        ]);
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id, 'name' => 'Main scene']);
        $document = FestivalDocument::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'title' => 'Participant guide',
            'path' => 'festivals/guide.pdf',
            'original_name' => 'guide.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);

        $assignment = FestivalJudgeAssignment::factory()->for($edition)->for($owner)->create([
            'account_id' => $account->id,
            'display_name' => 'Judge One',
        ]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $rubric = FestivalRubric::factory()->for($edition)->create([
            'account_id' => $account->id,
            'festival_category_id' => $category->id,
            'name' => 'Main criteria',
        ]);
        $section = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
        $section->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => 'Silk act',
        ]);
        $sheet = FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_judge_assignment_id' => $assignment->id,
            'festival_rubric_id' => $rubric->id,
        ]);

        $judges = $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.judging.judges.index', [$account, $edition]));
        $judges->assertOk();
        $this->assertSame([
            ['label' => 'Festivals', 'href' => route('dashboard.accounts.festivals.index', $account), 'current' => false],
            ['label' => $edition->title, 'href' => route('dashboard.accounts.festivals.show', [$account, $edition]), 'current' => false],
            ['label' => 'Judges', 'href' => null, 'current' => true],
        ], $this->breadcrumbItems($judges));

        foreach ([
            'dashboard.accounts.festivals.judging.judges.create' => ['Festivals', $edition->title, 'Judges', 'Add: Judge'],
            'dashboard.accounts.festivals.judging.judges.edit' => ['Festivals', $edition->title, 'Judges', 'Edit: Judge One'],
            'dashboard.accounts.festivals.judging.criteria.index' => ['Festivals', $edition->title, 'Criteria'],
            'dashboard.accounts.festivals.judging.criteria.create' => ['Festivals', $edition->title, 'Criteria', 'Add: Rubric'],
            'dashboard.accounts.festivals.judging.criteria.edit' => ['Festivals', $edition->title, 'Criteria', 'Edit: Main criteria'],
            'dashboard.accounts.festivals.judging.score-sheets.index' => ['Festivals', $edition->title, 'Score sheets'],
            'dashboard.accounts.festivals.judging.score-sheets.edit' => ['Festivals', $edition->title, 'Score sheets', 'Score sheet'],
            'dashboard.accounts.festivals.judging.results.index' => ['Festivals', $edition->title, 'Results'],
        ] as $routeName => $expectedLabels) {
            $parameters = match ($routeName) {
                'dashboard.accounts.festivals.judging.judges.edit' => [$account, $edition, $assignment],
                'dashboard.accounts.festivals.judging.criteria.edit' => [$account, $edition, $rubric],
                'dashboard.accounts.festivals.judging.score-sheets.edit' => [$account, $edition, $sheet],
                default => [$account, $edition],
            };
            $response = $this->withSession(['locale' => 'en'])->actingAs($owner)->get(route($routeName, $parameters));
            $response->assertOk();
            $this->assertSame($expectedLabels, array_column($this->breadcrumbItems($response), 'label'));
        }

        $categoryCreate = $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.categories.create', [$account, $edition]));
        $categoryCreate->assertOk();
        $this->assertSame([
            'Festivals',
            $edition->title,
            'Settings',
            'Categories',
            'Add: Category',
        ], array_column($this->breadcrumbItems($categoryCreate), 'label'));

        foreach ([
            'dashboard.accounts.festivals.settings.stages' => ['Festivals', $edition->title, 'Settings', 'Scenes'],
            'dashboard.accounts.festivals.stages.create' => ['Festivals', $edition->title, 'Settings', 'Scenes', 'Add: Scene'],
            'dashboard.accounts.festivals.stages.edit' => ['Festivals', $edition->title, 'Settings', 'Scenes', 'Edit: Main scene'],
        ] as $routeName => $expectedLabels) {
            $parameters = $routeName === 'dashboard.accounts.festivals.stages.edit'
                ? [$account, $edition, $stage]
                : [$account, $edition];
            $response = $this->withSession(['locale' => 'en'])->actingAs($owner)->get(route($routeName, $parameters));
            $response->assertOk();
            $this->assertSame($expectedLabels, array_column($this->breadcrumbItems($response), 'label'));
        }

        $documentEdit = $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.documents.edit', [$account, $edition, $document]));
        $documentEdit->assertOk();
        $items = $this->breadcrumbItems($documentEdit);
        $this->assertSame([
            'Festivals',
            $edition->title,
            'Settings',
            'Content & media',
            'Documents',
            'Edit: Participant guide',
        ], array_column($items, 'label'));
        $this->assertSame(route('dashboard.accounts.festivals.settings.content', [$account, $edition]), $items[3]['href']);
        $this->assertSame(route('dashboard.accounts.festivals.settings.content.documents', [$account, $edition]), $items[4]['href']);
        $this->assertTrue($items[5]['current']);
        $this->assertNull($items[5]['href']);

        $this->assertTrue($category->exists);
    }

    public function test_long_and_unsafe_labels_are_escaped_without_losing_accessible_text(): void
    {
        $account = Account::factory()->create(['default_language' => 'en']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $name = 'A very long location <script>alert("unsafe")</script> & accessible name that must remain complete';
        $location = Location::factory()->for($account)->create(['name' => $name]);

        $response = $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.locations.edit', [$account, $location]));

        $response->assertOk()
            ->assertDontSee('<script>alert("unsafe")</script>', false)
            ->assertSee('overflow-x-auto', false)
            ->assertSee('truncate', false);
        $items = $this->breadcrumbItems($response);
        $this->assertSame('Edit: '.$name, $items[3]['label']);
        $this->assertTrue($items[3]['current']);
    }

    public function test_platform_admin_account_pages_keep_the_platform_hierarchy(): void
    {
        $account = Account::factory()->create(['default_language' => 'en']);
        $platformAdmin = User::factory()->platformAdmin()->create();

        $response = $this->withSession(['locale' => 'en'])
            ->actingAs($platformAdmin)
            ->get(route('dashboard.accounts.show', $account));

        $response->assertOk();
        $this->assertSame([
            ['label' => 'System admin', 'href' => route('platform.index'), 'current' => false],
            ['label' => 'Studios', 'href' => route('platform.accounts.index'), 'current' => false],
            ['label' => $account->name, 'href' => null, 'current' => true],
        ], $this->breadcrumbItems($response));
    }

    public function test_nested_report_trail_links_back_through_the_report_index(): void
    {
        $account = Account::factory()->create(['default_language' => 'en']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        Location::factory()->for($account)->create();
        $trainer = Trainer::factory()->for($account)->for($owner, 'user')->create(['name' => 'Olena Trainer']);

        $response = $this->withSession(['locale' => 'en'])
            ->actingAs($owner)
            ->get(route('dashboard.accounts.reports.trainers.salary', [$account, $trainer]));

        $response->assertOk();
        $this->assertSame([
            ['label' => 'Workspace', 'href' => route('dashboard.index'), 'current' => false],
            ['label' => $account->name, 'href' => route('dashboard.accounts.show', $account), 'current' => false],
            ['label' => 'Reports', 'href' => route('dashboard.accounts.reports.index', $account), 'current' => false],
            ['label' => 'Trainers', 'href' => route('dashboard.accounts.reports.trainers', $account), 'current' => false],
            ['label' => 'Salary: Olena Trainer', 'href' => null, 'current' => true],
        ], $this->breadcrumbItems($response));
    }

    public function test_unknown_authenticated_route_fails_loudly_instead_of_using_a_fallback(): void
    {
        $account = Account::factory()->create();
        $request = Request::create('/app/dashboard/accounts/'.$account->id.'/unmapped');
        $route = new LaravelRoute('GET', 'app/dashboard/accounts/{account}/unmapped', fn (): string => 'unmapped');
        $route->name('dashboard.accounts.unmapped');
        $route->bind($request);
        $route->setParameter('account', $account);
        $request->setRouteResolver(fn (): LaravelRoute => $route);

        $this->expectException(LogicException::class);
        app(AppBreadcrumbs::class)->resolve($request);
    }

    /**
     * @return array{Account, FestivalEdition, User}
     */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Kyiv Aerial Open',
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        return [$account, $edition, $owner];
    }

    /**
     * @return list<array{label: string, href: ?string, current: bool}>
     */
    private function breadcrumbItems(TestResponse $response): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $navigation = $xpath->query('//nav[@data-app-breadcrumbs]')->item(0);

        $this->assertInstanceOf(DOMElement::class, $navigation);

        $items = [];
        foreach ($xpath->query('.//ol/li', $navigation) as $listItem) {
            $link = $xpath->query('./a', $listItem)->item(0);
            $current = $xpath->query('./span[@aria-current="page"]', $listItem)->item(0);
            $labelNode = $link ?? $current;

            $this->assertInstanceOf(DOMElement::class, $labelNode);
            $items[] = [
                'label' => trim((string) preg_replace('/\s+/u', ' ', $labelNode->textContent)),
                'href' => $link instanceof DOMElement ? $link->getAttribute('href') : null,
                'current' => $current instanceof DOMElement,
            ];
        }

        return $items;
    }
}
