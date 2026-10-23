<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalOnlineStream;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRubric;
use App\Models\FestivalScoreSheet;
use App\Models\FestivalSeries;
use App\Models\FestivalSponsor;
use App\Models\FestivalStreamEntitlement;
use App\Models\FestivalTicketOrder;
use App\Models\User;
use App\Support\Festivals\FestivalStreamAccessService;
use App\Support\Festivals\FestivalStreamViewer;
use App\View\Composers\FestivalSponsorComposer;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use Tests\TestCase;

class FestivalSponsorVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_each_landing_template_shows_its_sponsors_and_only_flagged_sponsors_in_the_footer(): void
    {
        [$account, $edition] = $this->festival();
        $featured = $this->sponsor($edition, 'Visible throughout this Festival', true);
        $landingOnly = $this->sponsor($edition, 'Only on this Festival landing');
        $otherEdition = FestivalEdition::factory()->for($edition->series)->published()->create(['account_id' => $account->id]);
        $foreign = $this->sponsor($otherEdition, 'Another Festival sponsor', true);
        [$otherAccount, $otherAccountEdition] = $this->festival();
        $otherAccountSponsor = $this->sponsor($otherAccountEdition, 'Another studio sponsor', true);
        $mismatchedSponsor = $this->sponsor($edition, 'Mismatched account sponsor', true);
        $mismatchedSponsor->update(['account_id' => $otherAccount->id]);

        foreach (['general', 'velvet_night'] as $template) {
            $edition->update(['landing_template' => $template]);
            $response = $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
                ->assertOk()
                ->assertSee('data-festival-sponsors-section', false)
                ->assertSee($featured->name)
                ->assertSee($landingOnly->name)
                ->assertSee($featured->short_description)
                ->assertSee($featured->giveaway_description)
                ->assertDontSee($foreign->name)
                ->assertDontSee($otherAccountSponsor->name)
                ->assertDontSee($mismatchedSponsor->name);

            $this->assertStripSponsors($response, [$featured]);
            $xpath = $this->xpath($response);
            $this->assertSame(1, $xpath->query('//*[@id="festival-sponsor-'.$featured->id.'"]')->length);
            $this->assertSame(1, $xpath->query('//*[@id="festival-sponsor-'.$landingOnly->id.'"]')->length);
        }
    }

    public function test_an_empty_festival_renders_no_sponsor_section_strip_or_dialog(): void
    {
        [$account, $edition] = $this->festival();

        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertDontSee('data-festival-sponsors-section', false)
            ->assertDontSee('data-festival-sponsors-strip', false)
            ->assertDontSee('data-festival-sponsor-dialog', false);
    }

    public function test_unchecked_sponsors_remain_on_the_landing_without_an_empty_footer_strip(): void
    {
        [$account, $edition] = $this->festival();
        $sponsor = $this->sponsor($edition, 'Landing only');

        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertSee($sponsor->name)
            ->assertSee('data-festival-sponsors-section', false)
            ->assertDontSee('data-festival-sponsors-strip', false);

        $this->get(route('public.festivals.entrance', [$account->slug, $edition->slug]))
            ->assertOk()
            ->assertDontSee($sponsor->name)
            ->assertDontSee('data-festival-sponsors-strip', false)
            ->assertDontSee('data-festival-sponsor-dialog', false);
    }

    public function test_edition_application_pages_show_only_their_own_flagged_sponsors(): void
    {
        [$account, $edition] = $this->festival();
        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
        ]);
        $featured = $this->sponsor($edition, 'Application sponsor', true);
        $landingOnly = $this->sponsor($edition, 'Landing sponsor');
        $otherEdition = FestivalEdition::factory()->for($edition->series)->published()->create(['account_id' => $account->id]);
        $foreign = $this->sponsor($otherEdition, 'Different application sponsor', true);

        foreach ([
            route('festival.portal.entries.create', [$account->slug, $edition->slug]),
            route('festival.portal.entries.show', [$account->slug, $entry]),
            route('festival.portal.entries.edit', [$account->slug, $entry]),
        ] as $url) {
            $response = $this->actingAs($portalUser, 'festival')->get($url)
                ->assertOk()
                ->assertDontSee($landingOnly->name)
                ->assertDontSee($foreign->name);
            $this->assertStripSponsors($response, [$featured]);
        }
    }

    public function test_account_wide_pages_never_pick_sponsors_from_an_iterated_edition(): void
    {
        [$account, $edition] = $this->festival();
        $sponsor = $this->sponsor($edition, 'Never shared account wide', true);
        $otherEdition = FestivalEdition::factory()->for($edition->series)->published()->create(['account_id' => $account->id]);
        $otherSponsor = $this->sponsor($otherEdition, 'Never mixed between editions', true);

        foreach (['public.festivals.index', 'festival.login', 'festival.judge.login'] as $routeName) {
            $this->get(route($routeName, $account->slug))
                ->assertOk()
                ->assertDontSee($sponsor->name)
                ->assertDontSee($otherSponsor->name)
                ->assertDontSee('data-festival-sponsors-strip', false);
        }

        $portalUser = FestivalPortalUser::factory()->for($account)->create();
        foreach (['festival.portal.dashboard', 'festival.portal.profile.edit', 'festival.portal.participants.index', 'festival.portal.entries.index', 'festival.portal.tickets.index'] as $routeName) {
            $this->actingAs($portalUser, 'festival')->get(route($routeName, $account->slug))
                ->assertOk()
                ->assertDontSee($sponsor->name)
                ->assertDontSee($otherSponsor->name)
                ->assertDontSee('data-festival-sponsors-strip', false);
        }

        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        FestivalJudgeAssignment::factory()->for($edition)->create([
            'account_id' => $account->id,
            'user_id' => null,
            'festival_portal_user_id' => $judge->id,
        ]);
        $this->actingAs($judge, 'festival')->get(route('festival.portal.judge.dashboard', $account->slug))
            ->assertOk()
            ->assertDontSee($sponsor->name)
            ->assertDontSee('data-festival-sponsors-strip', false);
    }

    public function test_order_payment_and_entrance_pages_keep_sponsors_with_the_exact_edition(): void
    {
        [$account, $edition] = $this->festival();
        $sponsor = $this->sponsor($edition, 'Checkout sponsor', true);
        $landingOnly = $this->sponsor($edition, 'Checkout hidden sponsor');
        $otherEdition = FestivalEdition::factory()->for($edition->series)->published()->create(['account_id' => $account->id]);
        $foreign = $this->sponsor($otherEdition, 'Other checkout sponsor', true);
        $order = FestivalTicketOrder::factory()->for($edition)->create([
            'account_id' => $account->id,
            'provider' => 'monopay',
            'amount_cents' => 10000,
            'gateway_checkout_payload' => [
                'request' => ['displayType' => 'iframe'],
                'response' => ['pageUrl' => 'https://pay.monobank.ua/invoice/sponsor-test'],
            ],
        ]);

        foreach ([
            route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]),
            route('public.festival-orders.payment', [$account->slug, $order->access_token_encrypted]),
            route('public.festivals.entrance', [$account->slug, $edition->slug]),
        ] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertDontSee($landingOnly->name)
                ->assertDontSee($foreign->name);
            $this->assertStripSponsors($response, [$sponsor]);
        }

        $this->get(route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]))
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_staff_workspace_and_guest_judge_pages_use_their_verified_edition(): void
    {
        [$account, $edition] = $this->festival();
        $sponsor = $this->sponsor($edition, 'Judging sponsor', true);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $edition]))->assertOk();
        $this->assertStripSponsors($response, [$sponsor]);

        $judge = FestivalPortalUser::factory()->for($account)->judge()->create();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $assignment = FestivalJudgeAssignment::factory()->for($edition)->create([
            'account_id' => $account->id,
            'user_id' => null,
            'festival_portal_user_id' => $judge->id,
        ]);
        $assignment->categories()->attach($category->id, ['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => FestivalPortalUser::factory()->for($account)->create()->id,
            'status' => 'accepted',
        ]);
        $rubric = FestivalRubric::factory()->for($edition)->create(['account_id' => $account->id, 'festival_category_id' => $category->id]);
        $section = $rubric->sections()->create(['account_id' => $account->id, 'name' => 'Technique', 'weight' => 1]);
        $section->criteria()->create(['account_id' => $account->id, 'name' => 'Execution', 'max_score' => 10, 'weight' => 1]);
        $sheet = FestivalScoreSheet::query()->create([
            'account_id' => $account->id,
            'festival_entry_id' => $entry->id,
            'festival_judge_assignment_id' => $assignment->id,
            'festival_rubric_id' => $rubric->id,
        ]);

        foreach ([
            route('festival.portal.judging.index', [$account->slug, $edition]),
            route('festival.portal.judging.edit', [$account->slug, $sheet]),
            route('festival.portal.battle-votes.index', [$account->slug, $edition->slug]),
        ] as $url) {
            $response = $this->actingAs($judge, 'festival')->get($url)->assertOk();
            $this->assertStripSponsors($response, [$sponsor]);
        }
    }

    public function test_landing_and_parent_layouts_share_one_sponsor_query_per_request(): void
    {
        [$account, $edition] = $this->festival();
        $this->sponsor($edition, 'One query sponsor', true);
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))
                ->assertOk()
                ->assertSee('data-festival-sponsors-section', false)
                ->assertSee('data-festival-sponsors-strip', false);
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'festival_sponsors'));
            $this->assertCount(1, $queries);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_composer_rejects_ambiguous_account_mismatches_and_embed_contexts(): void
    {
        [$account, $edition] = $this->festival();
        $this->sponsor($edition, 'Never leak sponsor', true);
        [$otherAccount, $otherEdition] = $this->festival();
        $stream = FestivalOnlineStream::factory()->for($edition, 'edition')->create(['account_id' => $account->id]);

        foreach ([
            ['festival.portal.dashboard', ['accountSlug' => $account->slug], ['account' => $account, 'edition' => $edition]],
            ['public.festivals.index', ['accountSlug' => $account->slug], ['account' => $account, 'edition' => $edition]],
            ['public.festivals.show', ['accountSlug' => $account->slug, 'editionSlug' => $otherEdition->slug], ['account' => $account, 'edition' => $edition]],
            ['public.festivals.show', ['accountSlug' => $otherAccount->slug, 'editionSlug' => $edition->slug], ['account' => $account, 'edition' => $edition]],
            ['dashboard.accounts.festivals.show', ['account' => $account, 'festivalEdition' => $otherEdition], ['account' => $account, 'edition' => $otherEdition]],
            ['dashboard.accounts.festivals.show', ['account' => $otherAccount, 'festivalEdition' => $edition], ['account' => $account, 'edition' => $edition]],
            ['festival.stream.player', ['path' => $stream->path], ['account' => $account, 'stream' => $stream, 'isEmbed' => true]],
            ['festival.stream.player', ['path' => 'different-stream'], ['account' => $account, 'stream' => $stream]],
        ] as [$routeName, $parameters, $data]) {
            $view = $this->compose($routeName, $parameters, $data);
            $this->assertCount(0, $view->getData()['festivalSponsors'], $routeName);
            $this->assertCount(0, $view->getData()['festivalFooterSponsors'], $routeName);
        }

        $account->enable_festivals = false;
        $view = $this->compose('public.festivals.show', ['accountSlug' => $account->slug, 'editionSlug' => $edition->slug], ['account' => $account, 'edition' => $edition]);
        $this->assertCount(0, $view->getData()['festivalFooterSponsors']);
    }

    public function test_stream_context_is_exact_and_other_festival_records_cannot_supply_sponsors(): void
    {
        [$account, $edition] = $this->festival();
        $sponsor = $this->sponsor($edition, 'Stream edition sponsor', true);
        $this->sponsor($edition, 'Stream hidden sponsor');
        $stream = FestivalOnlineStream::factory()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $view = $this->compose('festival.stream.player', ['path' => $stream->path], ['account' => $account, 'stream' => $stream]);
        $this->assertSame([$sponsor->id], $view->getData()['festivalFooterSponsors']->pluck('id')->all());

        [$otherAccount, $otherEdition] = $this->festival();
        $order = FestivalTicketOrder::factory()->for($otherEdition)->create(['account_id' => $otherAccount->id]);
        $view = $this->compose('public.festival-orders.show', ['accountSlug' => $account->slug, 'accessToken' => $order->access_token_encrypted], ['account' => $account, 'order' => $order]);
        $this->assertCount(0, $view->getData()['festivalFooterSponsors']);
        $view = $this->compose('festival.stream.player', ['path' => $stream->path], ['account' => $otherAccount, 'stream' => $stream]);
        $this->assertCount(0, $view->getData()['festivalFooterSponsors']);

        $order = FestivalTicketOrder::factory()->for($edition)->create(['account_id' => $account->id]);
        $view = $this->compose('public.festival-orders.show', ['accountSlug' => $account->slug, 'accessToken' => 'wrong-order-token'], ['account' => $account, 'order' => $order]);
        $this->assertCount(0, $view->getData()['festivalFooterSponsors']);
    }

    public function test_stream_viewers_see_their_edition_sponsors_but_the_embedded_staff_preview_does_not(): void
    {
        config([
            'services.festival_stream.public_url' => 'https://stream.ladna.test',
            'services.festival_stream.ip_hmac_key' => 'festival-sponsor-stream-test',
        ]);
        [$account, $edition] = $this->festival();
        $sponsor = $this->sponsor($edition, 'Stream viewer sponsor', true);
        $stream = FestivalOnlineStream::factory()->enabled()->for($edition, 'edition')->create(['account_id' => $account->id]);
        $entitlement = FestivalStreamEntitlement::factory()->for($stream, 'stream')->create();
        $access = app(FestivalStreamAccessService::class);
        $access->acquireLease($entitlement, $entitlement->portalUser, '203.0.113.40');
        $cookieName = $access->viewerCookieName($stream->path);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->withCookie($cookieName, $access->viewerCookie($entitlement, '203.0.113.40'))
            ->get(route('festival.stream.player', $stream->path))
            ->assertOk()
            ->assertViewHas('isEmbed', false);
        $this->assertStripSponsors($response, [$sponsor]);

        $owner = User::factory()->create();
        $account->addOwner($owner);
        $viewer = new FestivalStreamViewer($account, $stream, isStaffPreview: true, staffUser: $owner);
        $this->withCookie($cookieName, $access->viewerCredentialCookie($viewer, '203.0.113.40'))
            ->get(route('festival.stream.player', $stream->path))
            ->assertOk()
            ->assertViewHas('isEmbed', true)
            ->assertSee('data-festival-stream-staff-preview', false)
            ->assertDontSee($sponsor->name)
            ->assertDontSee('data-festival-sponsors-strip', false);
    }

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create([
            'enable_festivals' => true,
            'default_language' => 'en',
            'allowed_festival_landing_templates' => ['velvet_night'],
        ]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->for($series)->published()->create(['account_id' => $account->id]);

        return [$account, $edition];
    }

    private function sponsor(FestivalEdition $edition, string $name, bool $showOnAllPages = false): FestivalSponsor
    {
        return FestivalSponsor::factory()->for($edition, 'edition')->create([
            'account_id' => $edition->account_id,
            'name' => $name,
            'url' => 'https://example.com/sponsor',
            'short_description' => $name.' short description',
            'giveaway_description' => $name.' prize details',
            'show_on_all_pages' => $showOnAllPages,
        ]);
    }

    /** @param array<int, FestivalSponsor> $sponsors */
    private function assertStripSponsors(TestResponse $response, array $sponsors): void
    {
        $xpath = $this->xpath($response);
        $this->assertSame(1, $xpath->query('//*[@data-festival-sponsors-strip]')->length);
        $buttons = $xpath->query('//*[@data-festival-sponsors-strip]//*[@data-festival-sponsor-open]');
        $this->assertSame(array_map(fn (FestivalSponsor $sponsor): string => (string) $sponsor->id, $sponsors), array_map(
            fn ($node): string => $node->getAttribute('data-festival-sponsor-open'),
            iterator_to_array($buttons),
        ));
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML($response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $data
     */
    private function compose(string $routeName, array $parameters, array $data): View
    {
        $request = Request::create('/festival-sponsor-context');
        $route = new Route('GET', '/festival-sponsor-context', fn (): string => '');
        $route->name($routeName)->bind($request);
        foreach ($parameters as $key => $value) {
            $route->setParameter($key, $value);
        }
        $request->setRouteResolver(fn (): Route => $route);
        $this->app->instance('request', $request);
        $view = view('layouts.public', $data);
        app(FestivalSponsorComposer::class)->compose($view);

        return $view;
    }
}
