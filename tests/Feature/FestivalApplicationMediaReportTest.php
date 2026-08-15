<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\FestivalEntryStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalEntryRequirement;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalRequirementDefinition;
use App\Models\FestivalSeries;
use App\Models\FestivalSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

        $this->actingAs($owner)->get($reportUrl)->assertOk()->assertSee(__('app.festival_media_report'));
        $this->actingAs($registrationStaff)->get($reportUrl)->assertOk();
        $this->actingAs($financeStaff)->get($reportUrl)->assertForbidden();
        $this->actingAs($scheduleStaff)->get($reportUrl)->assertForbidden();
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
}
