<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\AiProvider;
use App\Enums\FestivalEntryStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AiProviderRequest;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalSubmission;
use App\Models\PlatformAiProviderCredential;
use App\Models\PlatformAiSetting;
use App\Models\User;
use App\Support\Ai\StudioAiUsageFirewall;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class FestivalApplicationMediaReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_report_renders_selected_media_and_metadata_once_for_active_applications(): void
    {
        Storage::fake('local');
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser->update(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
        $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Accepted, 'Featured performance');
        $participant = FestivalParticipant::factory()->for($portalUser)->create([
            'account_id' => $account->id,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
        ]);
        $artist = $this->definition($edition, [
            'name' => 'Track performer',
            'input_type' => 'short_text',
            'show_in_media_report' => true,
            'sort_order' => 1,
        ]);
        $participantNote = $this->definition($edition, [
            'name' => 'Participant cue',
            'input_type' => 'short_text',
            'subject_scope' => 'participant',
            'show_in_media_report' => true,
            'sort_order' => 2,
        ]);
        $audio = $this->definition($edition, [
            'name' => 'Performance music',
            'show_in_media_report' => true,
            'is_active' => false,
            'sort_order' => 3,
        ]);
        $video = $this->definition($edition, [
            'name' => 'Backdrop video',
            'show_in_media_report' => true,
            'sort_order' => 4,
        ]);
        $this->response($entry, $artist, value: 'The Example Artist');
        $this->response($entry, $participantNote, value: 'Stage left', participant: $participant);
        $audioSubmission = $this->response($entry, $audio, path: 'report/performance.mp3', mimeType: 'audio/mpeg');
        $videoSubmission = $this->response($entry, $video, path: 'report/backdrop.mp4', mimeType: 'video/mp4');

        $unmarkedMedia = $this->definition($edition, ['name' => 'Unmarked media', 'show_in_media_report' => false]);
        $unmarkedEntry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Unmarked performance');
        $this->response($unmarkedEntry, $unmarkedMedia, path: 'report/unmarked.mp3', mimeType: 'audio/mpeg');

        $textOnlyEntry = $this->entry($category, $portalUser, FestivalEntryStatus::Draft, 'Text only performance');
        $this->response($textOnlyEntry, $artist, value: 'No uploaded track');

        $rejectedEntry = $this->entry($category, $portalUser, FestivalEntryStatus::Rejected, 'Rejected performance');
        $this->response($rejectedEntry, $audio, path: 'report/rejected.mp3', mimeType: 'audio/mpeg');
        $withdrawnEntry = $this->entry($category, $portalUser, FestivalEntryStatus::Withdrawn, 'Withdrawn performance');
        $this->response($withdrawnEntry, $audio, path: 'report/withdrawn.mp3', mimeType: 'audio/mpeg');

        foreach ([FestivalEntryStatus::Draft, FestivalEntryStatus::Submitted, FestivalEntryStatus::UnderReview, FestivalEntryStatus::ChangesPending] as $status) {
            $activeEntry = $this->entry($category, $portalUser, $status, 'Active '.$status->value);
            $this->response($activeEntry, $audio, path: 'report/'.$status->value.'.mp3', mimeType: 'audio/mpeg');
        }

        [, $foreignEdition, $foreignCategory, $foreignPortalUser] = $this->festival();
        $foreignDefinition = $this->definition($foreignEdition, ['name' => 'Foreign media', 'show_in_media_report' => true]);
        $foreignEntry = $this->entry($foreignCategory, $foreignPortalUser, FestivalEntryStatus::Accepted, 'Foreign account performance');
        $this->response($foreignEntry, $foreignDefinition, path: 'report/foreign.mp3', mimeType: 'audio/mpeg');

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.media-report', [$account, $edition]));

        $response
            ->assertOk()
            ->assertSee(__('app.festival_media_report'))
            ->assertSee(__('app.festival_media_duplicates_button'))
            ->assertSee(route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]), false)
            ->assertSee('Featured performance')
            ->assertSee('Ada Lovelace')
            ->assertSee('Track performer')
            ->assertSee('The Example Artist')
            ->assertSee('Participant cue')
            ->assertSee('Hopper Grace')
            ->assertSee('Stage left')
            ->assertSee('<audio controls preload="none"', false)
            ->assertSee('<video controls playsinline preload="none"', false)
            ->assertSee(route('dashboard.accounts.festivals.submissions.view', [$account, $audioSubmission]), false)
            ->assertSee(route('dashboard.accounts.festivals.submissions.view', [$account, $videoSubmission]), false)
            ->assertSee(route('dashboard.accounts.festivals.submissions.download', [$account, $audioSubmission]), false)
            ->assertDontSee('Unmarked performance')
            ->assertDontSee('Text only performance')
            ->assertDontSee('Rejected performance')
            ->assertDontSee('Withdrawn performance')
            ->assertDontSee('Foreign account performance')
            ->assertSee('Active draft')
            ->assertSee('Active submitted')
            ->assertSee('Active under_review')
            ->assertSee('Active changes_pending')
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 5 && $entries->count() === 5);
    }

    public function test_live_field_selection_controls_existing_applications_and_non_playable_responses_do_not_qualify(): void
    {
        Storage::fake('local');
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Existing media application');
        $audio = $this->definition($edition, ['name' => 'Existing track']);
        $this->response($entry, $audio, path: 'report/existing.mp3', mimeType: 'audio/mpeg');
        $reportUrl = route('dashboard.accounts.festivals.applications.media-report', [$account, $edition]);

        $this->actingAs($owner)->get($reportUrl)
            ->assertOk()
            ->assertSee(__('app.festival_media_report_unconfigured'))
            ->assertDontSee('Existing media application');
        $this->get($reportUrl.'?q=anything')
            ->assertOk()
            ->assertSee(__('app.festival_media_report_unconfigured'));

        $audio->update(['show_in_media_report' => true]);
        $this->get($reportUrl)
            ->assertOk()
            ->assertSee('Existing media application');

        $audio->update(['show_in_media_report' => false]);
        $image = $this->definition($edition, ['name' => 'Selected image', 'show_in_media_report' => true]);
        $pdf = $this->definition($edition, ['name' => 'Selected PDF', 'show_in_media_report' => true]);
        $unsafe = $this->definition($edition, ['name' => 'Selected unsafe file', 'show_in_media_report' => true]);
        $missing = $this->definition($edition, ['name' => 'Missing file', 'show_in_media_report' => true]);
        $externalUrl = $this->definition($edition, [
            'name' => 'External video URL',
            'input_type' => 'url',
            'show_in_media_report' => true,
        ]);
        $this->response($entry, $image, path: 'report/poster.png', mimeType: 'image/png');
        $this->response($entry, $pdf, path: 'report/notes.pdf', mimeType: 'application/pdf');
        $this->response($entry, $unsafe, path: 'report/unsafe.html', mimeType: 'text/html');
        $this->response($entry, $missing, mimeType: 'audio/mpeg');
        $this->response($entry, $externalUrl, value: 'https://video.example.test/watch');

        $this->get($reportUrl)
            ->assertOk()
            ->assertSee(__('app.festival_media_report_empty'))
            ->assertDontSee('Existing media application');
        $this->get($reportUrl.'?q=no-match')
            ->assertOk()
            ->assertSee(__('app.festival_media_report_filtered_empty'));
    }

    public function test_report_combines_filters_ignores_foreign_categories_and_paginates_without_duplicate_rows(): void
    {
        Storage::fake('local');
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser->update(['email' => 'media-search@example.test']);
        $media = $this->definition($edition, ['name' => 'Selected track', 'show_in_media_report' => true]);

        foreach (range(1, 21) as $index) {
            $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Media page '.$index);
            $this->response($entry, $media, path: 'report/page-'.$index.'.mp3', mimeType: 'audio/mpeg');
        }

        $first = $edition->entries()->where('entry_name', 'Media page 1')->firstOrFail();
        $secondMedia = $this->definition($edition, ['name' => 'Selected video', 'show_in_media_report' => true]);
        $this->response($first, $secondMedia, path: 'report/page-1.mp4', mimeType: 'video/mp4');

        $paginated = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.media-report', [
            $account,
            $edition,
            'q' => 'Media page',
            'category' => $category->id,
        ]));
        $paginated->assertOk()->assertViewHas('entries', fn ($entries): bool => $entries->total() === 21 && $entries->count() === 20 && $entries->perPage() === 20);
        $nextPageUrl = $paginated->viewData('entries')->nextPageUrl();
        $this->assertNotNull($nextPageUrl);
        $this->assertStringContainsString('q=Media%20page', $nextPageUrl);
        $this->assertStringContainsString('category='.$category->id, $nextPageUrl);

        $applicantSearch = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.media-report', [
            $account,
            $edition,
            'q' => 'media-search@example.test',
        ]));
        $applicantSearch->assertOk()->assertViewHas('entries', fn ($entries): bool => $entries->total() === 21);

        [, $otherEdition, $foreignCategory] = $this->festival();
        $invalid = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.media-report', [
            $account,
            $edition,
            'category' => $foreignCategory->id,
        ]));
        $invalid
            ->assertOk()
            ->assertDontSee($otherEdition->title)
            ->assertDontSee($foreignCategory->name)
            ->assertViewHas('filters', ['q' => '', 'category' => ''])
            ->assertViewHas('entries', fn ($entries): bool => $entries->total() === 21);
    }

    public function test_report_is_registration_only_and_application_list_hides_its_action_from_finance_staff(): void
    {
        [$account, $edition] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $registrationStaff = $this->staff($account, StudioPermission::ManageFestivalRegistrations);
        $financeStaff = $this->staff($account, StudioPermission::ManageFestivalFinance);
        $scheduleStaff = $this->staff($account, StudioPermission::ManageFestivalSchedule);
        $reportUrl = route('dashboard.accounts.festivals.applications.media-report', [$account, $edition]);
        $duplicatesUrl = route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]);

        $this->actingAs($owner)->get($reportUrl)->assertOk()->assertSee(__('app.festival_media_report'));
        $this->actingAs($registrationStaff)->get($reportUrl)->assertOk();
        $this->actingAs($financeStaff)->get($reportUrl)->assertForbidden();
        $this->actingAs($scheduleStaff)->get($reportUrl)->assertForbidden();
        $this->actingAs($owner)->postJson($duplicatesUrl)->assertOk();
        $this->actingAs($registrationStaff)->postJson($duplicatesUrl)->assertOk();
        $this->actingAs($financeStaff)->postJson($duplicatesUrl)->assertForbidden();
        $this->actingAs($scheduleStaff)->postJson($duplicatesUrl)->assertForbidden();
        $this->actingAs($financeStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertDontSee($reportUrl, false);
        $this->actingAs($registrationStaff)
            ->get(route('dashboard.accounts.festivals.applications', [$account, $edition]))
            ->assertOk()
            ->assertSee($reportUrl, false);

        [, $foreignEdition] = $this->festival();
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.applications.media-report', [$account, $foreignEdition]))
            ->assertNotFound();
        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $foreignEdition]))
            ->assertNotFound();
    }

    public function test_ai_duplicate_check_uses_the_exact_eligible_report_corpus_and_rehydrates_validated_results(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $portalUser->update([
            'first_name' => 'Private',
            'last_name' => 'Applicant',
            'email' => 'private-applicant@example.test',
        ]);
        $media = $this->definition($edition, [
            'name' => 'Music upload',
            'show_in_media_report' => true,
        ]);
        $musicDetails = $this->definition($edition, [
            'name' => 'Artist and title',
            'input_type' => 'long_text',
            'show_in_media_report' => true,
        ]);
        $unmarkedText = $this->definition($edition, [
            'name' => 'Internal note',
            'input_type' => 'short_text',
            'show_in_media_report' => false,
        ]);
        $markedUrl = $this->definition($edition, [
            'name' => 'Music URL',
            'input_type' => 'url',
            'show_in_media_report' => true,
        ]);

        $first = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'First private performance');
        $this->response($first, $media, path: 'report/first-private-track.mp3', mimeType: 'audio/mpeg');
        $this->response($first, $musicDetails, value: 'Beyoncé — Crazy in Love');
        $this->response($first, $unmarkedText, value: 'SECRET_UNMARKED_VALUE');
        $this->response($first, $markedUrl, value: 'https://secret.example.test/music');

        $second = $this->entry($category, $portalUser, FestivalEntryStatus::Accepted, 'Second private performance');
        $this->response($second, $media, path: 'report/second-private-track.mp3', mimeType: 'audio/mpeg');
        $this->response($second, $musicDetails, value: 'Beyonce / Crazy in luv');

        $rejected = $this->entry($category, $portalUser, FestivalEntryStatus::Rejected, 'Rejected private performance');
        $this->response($rejected, $media, path: 'report/rejected-private-track.mp3', mimeType: 'audio/mpeg');
        $this->response($rejected, $musicDetails, value: 'SECRET_REJECTED_VALUE');

        $textOnly = $this->entry($category, $portalUser, FestivalEntryStatus::Draft, 'Text-only private performance');
        $this->response($textOnly, $musicDetails, value: 'SECRET_TEXT_ONLY_VALUE');

        $this->configureOpenAi(['owner_ai_assistant_enabled' => false]);
        $providerInput = null;
        Http::fake(function (ClientRequest $request) use (&$providerInput) {
            $providerInput = json_decode((string) data_get($request->data(), 'input.1.content'), true, flags: JSON_THROW_ON_ERROR);
            $applications = collect($providerInput['applications']);
            $firstApplication = $applications->first(fn (array $application): bool => data_get($application, 'fields.0.value') === 'Beyoncé — Crazy in Love');
            $secondApplication = $applications->first(fn (array $application): bool => data_get($application, 'fields.0.value') === 'Beyonce / Crazy in luv');

            return Http::response($this->openAiDuplicateResponse([
                'duplicate_groups' => [[
                    'application_refs' => [$firstApplication['application_ref'], $secondApplication['application_ref']],
                    'field_refs' => [$firstApplication['fields'][0]['field_ref'], $secondApplication['fields'][0]['field_ref']],
                    'reason' => 'Artist and title are the same despite spelling differences.',
                ]],
            ]));
        });

        $response = $this->actingAs($owner)->postJson(
            route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]),
        );

        $response
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertJsonPath('checked_applications', 2)
            ->assertJsonPath('checked_fields', 2)
            ->assertJsonCount(1, 'duplicate_groups')
            ->assertJsonCount(2, 'duplicate_groups.0.applications')
            ->assertJsonFragment(['name' => 'First private performance'])
            ->assertJsonFragment(['name' => 'Second private performance'])
            ->assertJsonFragment(['value' => 'Beyoncé — Crazy in Love'])
            ->assertJsonFragment(['value' => 'Beyonce / Crazy in luv'])
            ->assertJsonMissing(['name' => 'Rejected private performance']);

        $this->assertIsArray($providerInput);
        $serializedProviderInput = json_encode($providerInput, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Private Applicant', $serializedProviderInput);
        $this->assertStringNotContainsString('private-applicant@example.test', $serializedProviderInput);
        $this->assertStringNotContainsString('First private performance', $serializedProviderInput);
        $this->assertStringNotContainsString('first-private-track.mp3', $serializedProviderInput);
        $this->assertStringNotContainsString('SECRET_UNMARKED_VALUE', $serializedProviderInput);
        $this->assertStringNotContainsString('secret.example.test', $serializedProviderInput);
        $this->assertStringNotContainsString('SECRET_REJECTED_VALUE', $serializedProviderInput);
        $this->assertStringNotContainsString('SECRET_TEXT_ONLY_VALUE', $serializedProviderInput);

        Http::assertSent(function (ClientRequest $request) use ($owner): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['model'] === 'gpt-5.5'
                && $request['store'] === false
                && data_get($request->data(), 'text.format.name') === 'festival_media_duplicates'
                && $request['safety_identifier'] === app(StudioAiUsageFirewall::class)->safetyIdentifier($owner);
        });

        $providerRequest = AiProviderRequest::query()->sole();
        $this->assertSame('festival_media_report', $providerRequest->channel);
        $this->assertSame(AiProviderRequest::TypeFestivalMediaDuplicates, $providerRequest->request_type);
        $this->assertSame(AiProvider::OpenAiApiKey->value, $providerRequest->provider);
        $this->assertSame(AiProviderRequest::StatusSucceeded, $providerRequest->status);
    }

    public function test_ai_duplicate_check_skips_the_provider_when_fewer_than_two_eligible_applications_have_text(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $media = $this->definition($edition, ['show_in_media_report' => true]);
        $text = $this->definition($edition, [
            'input_type' => 'short_text',
            'show_in_media_report' => true,
        ]);
        $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Only candidate');
        $this->response($entry, $media, path: 'report/only-candidate.mp3', mimeType: 'audio/mpeg');
        $this->response($entry, $text, value: 'One music description');

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]))
            ->assertOk()
            ->assertExactJson([
                'checked_applications' => 1,
                'checked_fields' => 1,
                'duplicate_groups' => [],
            ]);

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderRequest::query()->count());
    }

    public function test_ai_duplicate_check_rejects_oversized_text_without_calling_the_provider(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $media = $this->definition($edition, ['show_in_media_report' => true]);
        $text = $this->definition($edition, [
            'input_type' => 'long_text',
            'show_in_media_report' => true,
        ]);

        foreach (range(1, 2) as $index) {
            $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Large candidate '.$index);
            $this->response($entry, $media, path: 'report/large-'.$index.'.mp3', mimeType: 'audio/mpeg');
            $this->response($entry, $text, value: str_repeat((string) $index, 50_000));
        }

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'input_too_large')
            ->assertJsonMissingPath('provider');

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderRequest::query()->count());
    }

    public function test_ai_duplicate_check_fails_closed_when_the_provider_returns_unknown_references(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $media = $this->definition($edition, ['show_in_media_report' => true]);
        $text = $this->definition($edition, [
            'input_type' => 'short_text',
            'show_in_media_report' => true,
        ]);

        foreach (range(1, 2) as $index) {
            $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Invalid response candidate '.$index);
            $this->response($entry, $media, path: 'report/invalid-'.$index.'.mp3', mimeType: 'audio/mpeg');
            $this->response($entry, $text, value: 'Music '.$index);
        }

        $this->configureOpenAi();
        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->openAiDuplicateResponse([
                'duplicate_groups' => [[
                    'application_refs' => ['application_1', 'unknown_application'],
                    'field_refs' => ['field_1', 'field_2'],
                    'reason' => 'Untrusted provider result.',
                ]],
            ])),
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'ai_unavailable')
            ->assertJsonMissingPath('duplicate_groups');

        $this->assertSame(AiProviderRequest::StatusSucceeded, AiProviderRequest::query()->sole()->status);
    }

    public function test_ai_duplicate_check_uses_the_centrally_selected_ollama_provider(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $media = $this->definition($edition, ['show_in_media_report' => true]);
        $text = $this->definition($edition, [
            'input_type' => 'short_text',
            'show_in_media_report' => true,
        ]);

        foreach (range(1, 2) as $index) {
            $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Ollama candidate '.$index);
            $this->response($entry, $media, path: 'report/ollama-'.$index.'.mp3', mimeType: 'audio/mpeg');
            $this->response($entry, $text, value: 'Distinct track '.$index);
        }

        $this->configureOllama();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode(['duplicate_groups' => []], JSON_THROW_ON_ERROR),
                ],
                'prompt_eval_count' => 80,
                'eval_count' => 10,
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]))
            ->assertOk()
            ->assertJsonPath('duplicate_groups', []);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://ollama.com/api/chat'
            && $request['model'] === 'gemma3:27b-cloud'
            && $request['format'] === 'json'
            && data_get($request->data(), 'options.temperature') === 0.0);
        $this->assertSame(AiProvider::OllamaCloud->value, AiProviderRequest::query()->sole()->provider);
    }

    public function test_ai_duplicate_check_rejects_an_ollama_object_masquerading_as_a_group_list(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $media = $this->definition($edition, ['show_in_media_report' => true]);
        $text = $this->definition($edition, [
            'input_type' => 'short_text',
            'show_in_media_report' => true,
        ]);

        foreach (range(1, 2) as $index) {
            $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Malformed Ollama candidate '.$index);
            $this->response($entry, $media, path: 'report/malformed-ollama-'.$index.'.mp3', mimeType: 'audio/mpeg');
            $this->response($entry, $text, value: 'Track '.$index);
        }

        $this->configureOllama();
        Http::fake([
            'ollama.com/api/chat' => Http::response([
                'message' => [
                    'role' => 'assistant',
                    'content' => '{"duplicate_groups":{}}',
                ],
            ]),
        ]);

        $this->actingAs($owner)
            ->postJson(route('dashboard.accounts.festivals.applications.media-report.duplicates', [$account, $edition]))
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'ai_unavailable')
            ->assertJsonMissingPath('duplicate_groups');

        $this->assertSame(AiProviderRequest::StatusSucceeded, AiProviderRequest::query()->sole()->status);
    }

    public function test_report_query_count_stays_constant_for_one_and_twenty_applications(): void
    {
        Storage::fake('local');
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $media = $this->definition($edition, ['name' => 'Query-count track', 'show_in_media_report' => true]);
        $first = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Query count 1');
        $this->response($first, $media, path: 'report/query-count-1.mp3', mimeType: 'audio/mpeg');
        $reportUrl = route('dashboard.accounts.festivals.applications.media-report', [$account, $edition]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get($reportUrl)->assertOk();
        $singleEntryQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(2, 20) as $index) {
            $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Query count '.$index);
            $this->response($entry, $media, path: 'report/query-count-'.$index.'.mp3', mimeType: 'audio/mpeg');
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($reportUrl)->assertOk();
        $twentyEntryQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($singleEntryQueryCount + 1, $twentyEntryQueryCount);
    }

    public function test_application_uses_inline_audio_and_private_preview_supports_byte_ranges(): void
    {
        Storage::fake('local');
        [$account, $edition, $category, $portalUser] = $this->festival();
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $entry = $this->entry($category, $portalUser, FestivalEntryStatus::Submitted, 'Playback application');
        $media = $this->definition($edition, ['name' => 'Playback track']);
        $submission = $this->response($entry, $media, path: 'report/range.mp3', mimeType: 'audio/mpeg', contents: '0123456789');
        $previewUrl = route('dashboard.accounts.festivals.submissions.view', [$account, $submission]);

        $application = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.applications.show', [$account, $edition, $entry]));
        $application
            ->assertOk()
            ->assertSee('<audio controls preload="none"', false)
            ->assertSee('<source src="'.$previewUrl.'" type="audio/mpeg">', false)
            ->assertSee(route('dashboard.accounts.festivals.submissions.download', [$account, $submission]), false);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]+href="'.preg_quote($previewUrl, '/').'"[^>]+target="_blank"/', $application->getContent());

        $partial = $this->withHeader('Range', 'bytes=2-5')->get($previewUrl);
        $this->assertInstanceOf(BinaryFileResponse::class, $partial->baseResponse);
        $partial
            ->assertStatus(206)
            ->assertHeader('accept-ranges', 'bytes')
            ->assertHeader('content-range', 'bytes 2-5/10')
            ->assertHeader('content-length', '4')
            ->assertHeader('content-type', 'audio/mpeg');
        $this->assertStringContainsString('private', (string) $partial->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', (string) $partial->headers->get('cache-control'));
        $this->assertStringNotContainsString('public', (string) $partial->headers->get('cache-control'));
        $this->assertStringStartsWith('inline;', (string) $partial->headers->get('content-disposition'));
        $this->assertSame('2345', $partial->streamedContent());
    }

    /** @return array{Account, FestivalEdition, FestivalCategory, FestivalPortalUser} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create([
            'account_id' => $account->id,
            'timezone' => 'Europe/Kyiv',
        ]);
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        return [$account, $edition, $category, $portalUser];
    }

    private function entry(FestivalCategory $category, FestivalPortalUser $portalUser, FestivalEntryStatus $status, string $name): FestivalEntry
    {
        return FestivalEntry::factory()->for($category)->create([
            'account_id' => $category->account_id,
            'festival_edition_id' => $category->festival_edition_id,
            'festival_portal_user_id' => $portalUser->id,
            'entry_name' => $name,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function definition(FestivalEdition $edition, array $attributes): FestivalRequirementDefinition
    {
        return FestivalRequirementDefinition::factory()->for($edition)->create([
            'account_id' => $edition->account_id,
            'type' => 'custom_document',
            'input_type' => 'file',
            'subject_scope' => 'entry',
            'allowed_extensions' => [],
            'allowed_mime_types' => [],
            ...$attributes,
        ]);
    }

    private function response(
        FestivalEntry $entry,
        FestivalRequirementDefinition $definition,
        ?string $value = null,
        ?string $path = null,
        ?string $mimeType = null,
        ?FestivalParticipant $participant = null,
        string $contents = 'media-contents',
    ): FestivalSubmission {
        $requirement = FestivalEntryRequirement::query()->create([
            'account_id' => $entry->account_id,
            'festival_entry_id' => $entry->id,
            'festival_requirement_definition_id' => $definition->id,
            'festival_participant_id' => $participant?->id,
            'subject_key' => $participant ? 'participant:'.$participant->id : 'entry',
            'status' => 'submitted',
        ]);
        if ($path !== null) {
            Storage::disk('local')->put($path, $contents);
        }

        return FestivalSubmission::query()->create([
            'account_id' => $entry->account_id,
            'festival_entry_id' => $entry->id,
            'festival_entry_requirement_id' => $requirement->id,
            'festival_portal_user_id' => $entry->festival_portal_user_id,
            'disk' => $path === null ? null : 'local',
            'path' => $path,
            'original_name' => $path === null ? null : basename($path),
            'mime_type' => $mimeType,
            'size_bytes' => $path === null ? null : strlen($contents),
            'value_json' => $path === null ? ['value' => $value] : null,
        ]);
    }

    private function staff(Account $account, StudioPermission $permission): User
    {
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [$permission->value],
        ]);

        return $staff;
    }

    /** @param array<string, mixed> $overrides */
    private function configureOpenAi(array $overrides = []): PlatformAiSetting
    {
        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();
        $setting = PlatformAiSetting::factory()->create([
            'active_provider' => AiProvider::OpenAiApiKey->value,
            'active_model' => 'gpt-5.5',
            ...$overrides,
        ]);
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OpenAiApiKey->value,
            'model' => 'gpt-5.5',
            'credentials' => ['api_key' => 'test-openai-key'],
            'is_configured' => true,
        ]);

        return $setting;
    }

    private function configureOllama(): PlatformAiSetting
    {
        PlatformAiSetting::query()->delete();
        PlatformAiProviderCredential::query()->delete();
        $setting = PlatformAiSetting::factory()->create([
            'active_provider' => AiProvider::OllamaCloud->value,
            'active_model' => 'gemma3:27b-cloud',
        ]);
        PlatformAiProviderCredential::factory()->create([
            'provider' => AiProvider::OllamaCloud->value,
            'model' => 'gemma3:27b-cloud',
            'credentials' => ['api_key' => 'test-ollama-key'],
            'is_configured' => true,
        ]);

        return $setting;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function openAiDuplicateResponse(array $content): array
    {
        return [
            'id' => 'resp_festival_duplicates',
            'status' => 'completed',
            'output' => [[
                'id' => 'message_festival_duplicates',
                'type' => 'message',
                'role' => 'assistant',
                'status' => 'completed',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'annotations' => [],
                ]],
            ]],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'total_tokens' => 120,
            ],
        ];
    }
}
