<?php

namespace Tests\Feature;

use App\Enums\FestivalAdmissionDeliveryMode;
use App\Enums\FestivalEditionStatus;
use App\Enums\FestivalRegistrantType;
use App\Enums\FestivalTeamMemberType;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalContentSection;
use App\Models\FestivalDirection;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalMedia;
use App\Models\FestivalParticipant;
use App\Models\FestivalRubric;
use App\Models\FestivalRubricCriterion;
use App\Models\FestivalRubricSection;
use App\Models\FestivalScheduleSlot;
use App\Models\FestivalSeries;
use App\Models\FestivalStage;
use App\Models\FestivalTicketOrder;
use App\Models\FestivalTimeline;
use App\Models\FestivalTimelineItem;
use App\Models\TelegramBotInstallation;
use App\Support\FestivalAuth\TelegramFestivalLoginTokenService;
use App\Support\Festivals\FestivalTelegramCheckoutHandoff;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use App\Support\Telegram\TelegramMiniAppInitDataValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalTelegramMiniAppTest extends TestCase
{
    use DatabaseTransactions;

    public function test_init_data_requires_exact_bot_hmac_and_a_fresh_private_session(): void
    {
        [, , , $installation] = $this->festival();
        $valid = $this->initData($installation, '7001001');
        $result = app(TelegramMiniAppInitDataValidator::class)->validate($valid, $installation);

        $this->assertSame('7001001', $result['user']['id']);

        try {
            app(TelegramMiniAppInitDataValidator::class)->validate(str_replace('Telegram', 'Tampered', $valid), $installation);
            $this->fail('Expected tampered Telegram init data to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('init_data', $exception->errors());
        }

        $stale = $this->initData($installation, '7001001', now()->subMinutes(6)->timestamp);
        $this->expectException(ValidationException::class);
        app(TelegramMiniAppInitDataValidator::class)->validate($stale, $installation);
    }

    public function test_series_mini_app_shell_renders_public_data_without_exposing_the_bot_token(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/festival-desktop.jpg',
            'alt_text' => 'Nearest Festival',
            'is_cover' => true,
        ]);
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/festival-mobile.jpg',
            'alt_text' => 'Nearest Festival Mobile',
            'is_mobile_cover' => true,
        ]);
        $fartherEdition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'starts_at' => now()->addMonths(2),
            'ends_at' => now()->addMonths(2)->addHours(6),
        ]);
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $fartherEdition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/farther-festival.jpg',
            'is_cover' => true,
        ]);
        $otherSeries = FestivalSeries::factory()->for($account)->create();
        $otherEdition = FestivalEdition::factory()->published()->for($otherSeries)->create([
            'account_id' => $account->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(6),
        ]);
        FestivalMedia::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $otherEdition->id,
            'kind' => 'image',
            'external_url' => 'https://cdn.example.test/other-series.jpg',
            'is_cover' => true,
        ]);

        $this->get(route('public.festival-telegram.show', [$account->slug, $series->slug]))
            ->assertOk()
            ->assertSee('data-festival-telegram-mini-app', false)
            ->assertSee('data-festival-telegram-hero-edition="'.$edition->id.'"', false)
            ->assertSee('https://cdn.example.test/festival-desktop.jpg', false)
            ->assertSee('https://cdn.example.test/festival-mobile.jpg', false)
            ->assertDontSee('https://cdn.example.test/farther-festival.jpg', false)
            ->assertDontSee('https://cdn.example.test/other-series.jpg', false)
            ->assertDontSee($series->summary)
            ->assertDontSee('data-festival-telegram-tab="preferences"', false)
            ->assertSee('"open_page":'.json_encode(__('app.festival_telegram_open_page')), false)
            ->assertDontSee('Open in Ladna')
            ->assertSee($edition->title)
            ->assertDontSee((string) $installation->tokenValue(), false);
    }

    public function test_bootstrap_is_series_scoped_and_releases_private_data_only_after_authorization(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        $otherSeries = FestivalSeries::factory()->for($account)->create();
        $otherEdition = FestivalEdition::factory()->published()->for($otherSeries)->create([
            'account_id' => $account->id,
            'title' => 'Other Series Festival',
        ]);
        $initData = $this->initData($installation, '7002001');
        $route = route('public.festival-telegram.bootstrap', [$account->slug, $series->slug]);

        $this->postJson($route, ['init_data' => $initData])
            ->assertOk()
            ->assertJsonPath('authorized', false)
            ->assertJsonMissing(['registrant'])
            ->assertJsonFragment(['title' => $edition->title])
            ->assertJsonMissing(['title' => $otherEdition->title]);

        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7002001',
            '7002001',
            '+380501234501',
            ['first_name' => 'Telegram', 'last_name' => 'Registrant', 'language_code' => 'en'],
        );
        $this->assertSame(FestivalRegistrantType::AdultAthlete, $linked['registrant']->registrant_type);
        $performer = FestivalParticipant::factory()->for($linked['registrant'])->create([
            'account_id' => $account->id,
            'first_name' => 'MiniAppPerformer',
            'member_type' => FestivalTeamMemberType::Performer,
        ]);
        FestivalParticipant::factory()->for($linked['registrant'])->create([
            'account_id' => $account->id,
            'first_name' => 'MiniAppHelper',
            'member_type' => FestivalTeamMemberType::Helper,
        ]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $stage = FestivalStage::factory()->for($edition)->create(['account_id' => $account->id]);
        FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'festival_category_id' => $category->id,
            'type' => 'category_header',
            'published_at' => now(),
        ]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $linked['registrant']->id,
            'entry_name' => 'Series-only entry',
        ]);

        $authorizedResponse = $this->postJson($route, ['init_data' => $initData]);
        $authorizedResponse
            ->assertOk()
            ->assertJsonPath('authorized', true)
            ->assertJsonPath('identity.registrant_linked', true)
            ->assertJsonPath('registrant.participants.0.id', $performer->id)
            ->assertJsonPath('registrant.participants.0.member_type', FestivalTeamMemberType::Performer->value)
            ->assertJsonFragment(['name' => 'Series-only entry'])
            ->assertJsonFragment(['edition_id' => $edition->id, 'id' => $entry->id])
            ->assertJsonFragment(['name' => $category->name])
            ->assertJsonMissing(['title' => $otherEdition->title])
            ->assertJsonMissing(['name' => 'MiniAppHelper']);
    }

    public function test_exact_festival_includes_only_active_public_sanitized_content_sections(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        FestivalContentSection::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'key' => 'about-festival',
            'title' => 'About this Festival',
            'body_html' => '<p>Authored Festival information.</p><script>alert("unsafe")</script>',
            'visibility' => 'public',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        FestivalContentSection::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'key' => 'portal-only',
            'title' => 'Portal-only information',
            'body_html' => '<p>Private.</p>',
            'visibility' => 'portal',
            'is_active' => true,
            'sort_order' => 20,
        ]);
        FestivalContentSection::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'key' => 'inactive-public',
            'title' => 'Inactive information',
            'body_html' => '<p>Inactive.</p>',
            'visibility' => 'public',
            'is_active' => false,
            'sort_order' => 30,
        ]);

        $this->postJson(route('public.festival-telegram.bootstrap', [$account->slug, $series->slug]), [
            'init_data' => $this->initData($installation, '7002501'),
        ])
            ->assertOk()
            ->assertJsonPath('editions.0.sections.0.title', 'About this Festival')
            ->assertJsonPath('editions.0.sections.0.body_html', '<p>Authored Festival information.</p>')
            ->assertJsonMissing(['title' => 'Portal-only information'])
            ->assertJsonMissing(['title' => 'Inactive information']);
    }

    public function test_exact_festival_includes_public_rules_categories_criteria_and_ordered_program_without_private_facts(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        $edition->forceFill([
            'description_html' => '<p>Full Festival description.</p><script>alert("unsafe")</script>',
            'rules_html' => '<p>Competition rules.</p><script>alert("unsafe")</script>',
        ])->save();
        $direction = FestivalDirection::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'name' => 'Pole Art',
            'sort_order' => 20,
        ]);
        $category = FestivalCategory::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_direction_id' => $direction->id,
            'festival_workflow_id' => null,
            'name' => 'Solo Professionals',
            'min_members' => 1,
            'max_members' => 1,
            'min_age' => 18,
            'max_age' => null,
            'min_duration_seconds' => 150,
            'max_duration_seconds' => 195,
            'requirements_html' => '<p>Bring a safe costume.</p><script>alert("unsafe")</script>',
            'sort_order' => 30,
        ]);
        FestivalCategory::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_direction_id' => $direction->id,
            'name' => 'Hidden Category',
            'is_active' => false,
        ]);
        $rubric = FestivalRubric::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category->id,
            'name' => 'Solo judging protocol',
        ]);
        $section = FestivalRubricSection::query()->create([
            'account_id' => $account->id,
            'festival_rubric_id' => $rubric->id,
            'name' => 'Technique',
            'weight' => 1,
            'contribution' => 'award',
            'sort_order' => 10,
        ]);
        FestivalRubricCriterion::query()->create([
            'account_id' => $account->id,
            'festival_rubric_section_id' => $section->id,
            'name' => 'Execution quality',
            'max_score' => 10,
            'weight' => 1,
            'sort_order' => 10,
        ]);
        FestivalRubric::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_category_id' => $category->id,
            'name' => 'Hidden protocol',
            'is_active' => false,
        ]);
        $stage = FestivalStage::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'name' => 'Main stage',
            'sort_order' => 10,
        ]);
        $header = FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'type' => 'free_header',
            'name' => 'Evening block',
            'sort_order' => 10,
            'published_at' => now(),
        ]);
        FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'parent_id' => $header->id,
            'type' => 'custom',
            'name' => 'Opening show',
            'starts_at' => $edition->starts_at->copy()->addHour(),
            'ends_at' => $edition->starts_at->copy()->addHour()->addMinutes(15),
            'sort_order' => 20,
            'notes' => 'Internal stage note',
            'reschedule_reason' => 'Internal reschedule reason',
            'published_at' => now(),
        ]);
        FestivalScheduleSlot::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'type' => 'custom',
            'name' => 'Unpublished rehearsal',
            'starts_at' => $edition->starts_at->copy()->addHours(2),
            'ends_at' => $edition->starts_at->copy()->addHours(2)->addMinutes(15),
            'sort_order' => 30,
            'published_at' => null,
        ]);

        $response = $this->postJson(route('public.festival-telegram.bootstrap', [$account->slug, $series->slug]), [
            'init_data' => $this->initData($installation, '7002601'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('editions.0.description_html', '<p>Full Festival description.</p>')
            ->assertJsonPath('editions.0.rules_html', '<p>Competition rules.</p>')
            ->assertJsonPath('editions.0.category_groups.0.name', 'Pole Art')
            ->assertJsonPath('editions.0.category_groups.0.categories.0.name', 'Solo Professionals')
            ->assertJsonPath('editions.0.category_groups.0.categories.0.requirements_html', '<p>Bring a safe costume.</p>')
            ->assertJsonPath('editions.0.rubrics.0.name', 'Solo judging protocol')
            ->assertJsonPath('editions.0.rubrics.0.sections.0.name', 'Technique')
            ->assertJsonPath('editions.0.rubrics.0.sections.0.criteria.0.name', 'Execution quality')
            ->assertJsonPath('editions.0.program.0.stage', 'Main stage')
            ->assertJsonPath('editions.0.program.0.items.0.name', 'Evening block')
            ->assertJsonPath('editions.0.program.0.items.0.children.0.name', 'Opening show')
            ->assertJsonMissing(['name' => 'Hidden Category'])
            ->assertJsonMissing(['name' => 'Hidden protocol'])
            ->assertJsonMissing(['name' => 'Unpublished rehearsal']);
        $this->assertStringNotContainsString('festival_workflow_id', $response->getContent());
        $this->assertStringNotContainsString('Internal stage note', $response->getContent());
        $this->assertStringNotContainsString('Internal reschedule reason', $response->getContent());
    }

    public function test_public_timeline_poll_shows_started_current_activity_without_phone_authorization_or_internal_facts(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        $edition->forceFill([
            'status' => FestivalEditionStatus::InProgress,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(5),
        ])->save();
        $stage = FestivalStage::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'name' => 'Main stage',
        ]);
        $timeline = FestivalTimeline::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_stage_id' => $stage->id,
            'started_at' => now()->subMinute(),
            'next_transition_at' => now()->addMinutes(4),
        ]);
        $current = FestivalTimelineItem::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_timeline_id' => $timeline->id,
            'label' => 'Current performance',
            'entry_reference' => 'PRIVATE-REFERENCE',
            'notes' => 'Private operator note',
            'planned_starts_at' => now()->subMinute(),
            'planned_ends_at' => now()->addMinutes(4),
            'sort_order' => 10,
        ]);
        FestivalTimelineItem::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_timeline_id' => $timeline->id,
            'label' => 'Disabled private item',
            'notes' => 'Disabled private note',
            'sort_order' => 20,
            'is_enabled' => false,
        ]);
        $timeline->forceFill(['active_item_id' => $current->id])->save();

        $response = $this->postJson(route('public.festival-telegram.timeline', [$account->slug, $series->slug]), [
            'init_data' => $this->initData($installation, '7002701'),
        ]);

        $response
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('editions.0.status', FestivalEditionStatus::InProgress->value)
            ->assertJsonPath('editions.0.timeline.0.scene_name', 'Main stage')
            ->assertJsonPath('editions.0.timeline.0.items.0.label', 'Current performance')
            ->assertJsonPath('editions.0.timeline.0.items.0.status', 'active')
            ->assertJsonMissing(['label' => 'Disabled private item']);
        foreach (['active_item_id', 'last_finished_item_id', 'entry_reference', 'notes', 'model', 'PRIVATE-REFERENCE'] as $privateFact) {
            $this->assertStringNotContainsString($privateFact, $response->getContent());
        }
    }

    public function test_registrant_login_is_single_use_and_fails_after_revocation(): void
    {
        [$account, $series, , $installation] = $this->festival();
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7003001',
            '7003001',
            '+380501234502',
            ['first_name' => 'One', 'last_name' => 'Use'],
        );
        $tokens = app(TelegramFestivalLoginTokenService::class);
        $url = $tokens->issueRegistrantUrl($series, $linked['authorization'], $linked['registrant'], 'dashboard');

        $this->get($url)->assertRedirect(route('festival.portal.dashboard', $account->slug));
        $this->assertAuthenticatedAs($linked['registrant'], 'festival');
        Auth::guard('festival')->logout();
        $this->get($url)->assertForbidden();

        $revokedUrl = $tokens->issueRegistrantUrl($series, $linked['authorization'], $linked['registrant'], 'dashboard');
        $linked['authorization']->forceFill([
            'status' => TelegramChatAuthorizationStatus::Revoked,
            'revoked_at' => now(),
        ])->save();

        $this->get($revokedUrl)->assertForbidden();
        $this->assertGuest('festival');
    }

    public function test_authenticated_checkout_links_the_exact_created_guest_while_anonymous_checkout_does_not(): void
    {
        [$account, $series, $edition, $installation] = $this->festival();
        $type = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::Venue,
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7004001',
            '7004001',
            '+380501234503',
            ['first_name' => 'Ticket', 'last_name' => 'Buyer'],
        );
        $handoffUrl = app(FestivalTelegramCheckoutHandoff::class)->issueUrl($series, $edition, $linked['authorization']);
        $this->get($handoffUrl)->assertRedirect(route('public.festivals.show', [$account->slug, $edition->slug]).'#festival-admission');

        $response = $this->post(route('public.festivals.admission.store', [$account->slug, $edition->slug]), $this->checkoutPayload($type, 'telegram-buyer@example.test', '+380501234599'));
        $response->assertSessionHasNoErrors();
        $order = FestivalTicketOrder::query()->whereBelongsTo($account)->sole();
        $guest = $order->portalUser;

        $response->assertRedirect(route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]));
        $this->assertSame('7004001', $guest->telegram_user_id);
        $this->assertDatabaseHas('telegram_festival_portal_links', [
            'telegram_chat_authorization_id' => $linked['authorization']->id,
            'festival_portal_user_id' => $guest->id,
        ]);

        [$otherAccount, , $otherEdition] = $this->festival();
        $anonymousType = FestivalAdmissionType::factory()->for($otherEdition)->create([
            'account_id' => $otherAccount->id,
            'delivery_mode' => FestivalAdmissionDeliveryMode::Venue,
            'price_cents' => 0,
            'inventory' => 10,
        ]);
        $this->post(
            route('public.festivals.admission.store', [$otherAccount->slug, $otherEdition->slug]),
            $this->checkoutPayload($anonymousType, 'anonymous@example.test', '+380501234598'),
        )->assertRedirect();
        $anonymousOrder = FestivalTicketOrder::query()->whereBelongsTo($otherAccount)->sole();

        $this->assertNull($anonymousOrder->portalUser->telegram_user_id);
        $this->assertFalse($anonymousOrder->portalUser->telegramFestivalLinks()->exists());
    }

    /** @return array{Account, FestivalSeries, FestivalEdition, TelegramBotInstallation} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(6),
        ]);
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival,
            'encrypted_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'is_enabled' => true,
        ]);

        return [$account, $series, $edition, $installation];
    }

    private function initData(TelegramBotInstallation $installation, string $telegramUserId, ?int $authDate = null): string
    {
        $parameters = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'query_id' => 'AAH-synthetic-query',
            'user' => json_encode([
                'id' => (int) $telegramUserId,
                'first_name' => 'Telegram',
                'last_name' => 'Tester',
                'username' => 'festival_tester',
                'language_code' => 'en',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
        ksort($parameters);
        $checkString = collect($parameters)->map(fn (string $value, string $key): string => $key.'='.$value)->implode("\n");
        $secretKey = hash_hmac('sha256', (string) $installation->tokenValue(), 'WebAppData', true);
        $parameters['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return http_build_query($parameters);
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(FestivalAdmissionType $type, string $email, string $phone): array
    {
        return [
            'buyer_name' => 'Telegram Guest',
            'buyer_email' => $email,
            'buyer_email_confirmation' => $email,
            'buyer_phone' => $phone,
            'items' => [$type->id => 1],
            'terms' => '1',
        ];
    }
}
