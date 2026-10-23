<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalSeries;
use App\Models\FestivalSponsor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FestivalSponsorManagementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_factory_keeps_default_sponsor_and_edition_in_the_same_account(): void
    {
        $sponsor = FestivalSponsor::factory()->create();

        $this->assertSame($sponsor->edition->account_id, $sponsor->account_id);
        $this->assertFalse($sponsor->show_on_all_pages);
    }

    public function test_owner_can_open_the_dedicated_sponsor_pages(): void
    {
        $this->assertTrue(Route::has('dashboard.accounts.festivals.settings.sponsors'));
        $this->assertTrue(Route::has('dashboard.accounts.festivals.sponsors.create'));

        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_sponsors_empty'))
            ->assertSee(route('dashboard.accounts.festivals.sponsors.create', [$account, $edition]), false)
            ->assertDontSee('name="short_description"', false);

        $this->get(route('dashboard.accounts.festivals.sponsors.create', [$account, $edition]))
            ->assertOk()
            ->assertSee('name="logo"', false)
            ->assertSee('name="show_on_all_pages"', false);
    }

    public function test_owner_can_create_edit_replace_logo_toggle_footer_and_delete_a_sponsor(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $indexRoute = route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]);

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), [
                ...$this->payload(),
                'logo' => UploadedFile::fake()->image('sponsor.png', 400, 200),
                'show_on_all_pages' => 1,
                'account_id' => 999999,
                'festival_edition_id' => 999999,
                'logo_path' => 'another-account/logo.png',
            ])
            ->assertRedirect($indexRoute)
            ->assertSessionHasNoErrors();

        $sponsor = $edition->sponsors()->sole();
        $originalPath = $sponsor->logo_path;
        $this->assertSame($account->id, $sponsor->account_id);
        $this->assertSame($edition->id, $sponsor->festival_edition_id);
        $this->assertTrue($sponsor->show_on_all_pages);
        $this->assertStringStartsWith("festivals/{$account->id}/{$edition->id}/sponsors/", $originalPath);
        Storage::disk('public')->assertExists($originalPath);

        $this->get(route('dashboard.accounts.festivals.sponsors.edit', [$account, $edition, $sponsor]))
            ->assertOk()
            ->assertSee($sponsor->name)
            ->assertSee($sponsor->logoUrl(), false)
            ->assertSee('name="giveaway_description"', false);

        $updateRoute = route('dashboard.accounts.festivals.sponsors.update', [$account, $edition, $sponsor]);
        $this->put($updateRoute, [...$this->payload(), 'name' => 'Updated partner', 'show_on_all_pages' => 0])
            ->assertRedirect($indexRoute)
            ->assertSessionHasNoErrors();
        $this->assertSame($originalPath, $sponsor->refresh()->logo_path);
        $this->assertSame('Updated partner', $sponsor->name);
        $this->assertFalse($sponsor->show_on_all_pages);

        $this->put($updateRoute, [
            ...$this->payload(),
            'logo' => UploadedFile::fake()->image('new-sponsor.webp', 300, 300),
            'show_on_all_pages' => 1,
        ])->assertRedirect($indexRoute)->assertSessionHasNoErrors();
        $replacementPath = $sponsor->refresh()->logo_path;
        $this->assertNotSame($originalPath, $replacementPath);
        $this->assertTrue($sponsor->show_on_all_pages);
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($replacementPath);
        Storage::disk('public')->put('unrelated-logo.png', 'preserve me');

        $this->delete(route('dashboard.accounts.festivals.sponsors.destroy', [$account, $edition, $sponsor]))
            ->assertRedirect($indexRoute)
            ->assertSessionHas('status', __('app.festival_sponsor_deleted'));
        $this->assertModelMissing($sponsor);
        Storage::disk('public')->assertMissing($replacementPath);
        Storage::disk('public')->assertExists('unrelated-logo.png');
    }

    public function test_flag_defaults_to_false_and_optional_text_fields_can_be_empty(): void
    {
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), [
            'name' => 'Local partner',
            'short_description' => 'A local creative studio.',
            'logo' => UploadedFile::fake()->image('partner.jpg'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sponsor = $edition->sponsors()->sole();
        $this->assertFalse($sponsor->show_on_all_pages);
        $this->assertNull($sponsor->url);
        $this->assertNull($sponsor->giveaway_description);
    }

    public function test_create_requires_name_logo_and_short_description(): void
    {
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)
            ->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), [])
            ->assertSessionHasErrors(['name', 'logo', 'short_description']);

        $this->assertSame(0, $edition->sponsors()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_sponsor_fields_are_rejected(string $field, mixed $value): void
    {
        [$account, $edition, $owner] = $this->festival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), [
            ...$this->payload(),
            'logo' => UploadedFile::fake()->image('partner.png'),
            $field => $value,
        ])->assertSessionHasErrors($field);

        $this->assertSame(0, $edition->sponsors()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidFields(): array
    {
        return [
            'name length' => ['name', str_repeat('a', 256)],
            'short introduction length' => ['short_description', str_repeat('a', 501)],
            'giveaway length' => ['giveaway_description', str_repeat('a', 10001)],
            'javascript URL' => ['url', 'javascript:alert(1)'],
            'data URL' => ['url', 'data:text/html,unsafe'],
            'FTP URL' => ['url', 'ftp://example.com'],
            'URL length' => ['url', 'https://example.com/'.str_repeat('a', 2048)],
            'invalid flag' => ['show_on_all_pages', 'yes please'],
        ];
    }

    public function test_unsafe_and_oversized_logos_are_rejected(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $this->actingAs($owner);

        foreach ([
            UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml'),
            UploadedFile::fake()->create('pretend.png', 10, 'text/plain'),
            UploadedFile::fake()->image('large.png')->size(4097),
        ] as $logo) {
            $this->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), [
                ...$this->payload(),
                'logo' => $logo,
            ])->assertSessionHasErrors('logo');
        }

        $this->assertSame(0, $edition->sponsors()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_invalid_update_preserves_the_record_and_original_logo(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $sponsor = FestivalSponsor::factory()->for($edition)->create();
        Storage::disk('public')->put($sponsor->logo_path, 'original');
        $originalName = $sponsor->name;
        $originalPath = $sponsor->logo_path;

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.sponsors.update', [$account, $edition, $sponsor]), [
            ...$this->payload(),
            'url' => 'javascript:alert(1)',
            'logo' => UploadedFile::fake()->image('replacement.png'),
        ])->assertSessionHasErrors('url');

        $this->assertSame($originalName, $sponsor->refresh()->name);
        $this->assertSame($originalPath, $sponsor->logo_path);
        $this->assertSame([$originalPath], Storage::disk('public')->allFiles());
    }

    public function test_sponsors_are_scoped_to_the_exact_account_and_edition(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $otherEdition = FestivalEdition::factory()->published()->for($edition->series)->create(['account_id' => $account->id]);
        $otherEditionSponsor = FestivalSponsor::factory()->for($otherEdition)->create(['name' => 'Wrong edition sentinel']);
        [$otherAccount, $foreignEdition] = $this->festival();
        $foreignSponsor = FestivalSponsor::factory()->for($foreignEdition)->create(['name' => 'Foreign account sentinel']);
        $this->actingAs($owner);

        foreach ([$otherEditionSponsor, $foreignSponsor] as $sponsor) {
            $this->get(route('dashboard.accounts.festivals.sponsors.edit', [$account, $edition, $sponsor]))->assertNotFound();
            $this->put(route('dashboard.accounts.festivals.sponsors.update', [$account, $edition, $sponsor]), $this->payload())->assertNotFound();
            $this->delete(route('dashboard.accounts.festivals.sponsors.destroy', [$account, $edition, $sponsor]))->assertNotFound();
            $this->assertModelExists($sponsor);
        }

        $this->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]))
            ->assertOk()->assertDontSee('Wrong edition sentinel')->assertDontSee('Foreign account sentinel');
        $this->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $foreignEdition]))->assertNotFound();
        $this->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $foreignEdition]), [
            ...$this->payload(),
            'logo' => UploadedFile::fake()->image('partner.png'),
        ])->assertNotFound();
        $this->get(route('dashboard.accounts.festivals.settings.sponsors', [$otherAccount, $foreignEdition]))->assertForbidden();
    }

    public function test_registration_or_finance_permissions_do_not_grant_sponsor_management(): void
    {
        [$account, $edition] = $this->festival();
        $sponsor = FestivalSponsor::factory()->for($edition)->create();
        $staff = User::factory()->create();
        $account->users()->attach($staff->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivalRegistrations->value, StudioPermission::ManageFestivalFinance->value],
        ]);
        $this->actingAs($staff);

        $this->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]))->assertForbidden();
        $this->get(route('dashboard.accounts.festivals.sponsors.create', [$account, $edition]))->assertForbidden();
        $this->get(route('dashboard.accounts.festivals.sponsors.edit', [$account, $edition, $sponsor]))->assertForbidden();
        $this->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), $this->payload())->assertForbidden();
        $this->put(route('dashboard.accounts.festivals.sponsors.update', [$account, $edition, $sponsor]), $this->payload())->assertForbidden();
        $this->delete(route('dashboard.accounts.festivals.sponsors.destroy', [$account, $edition, $sponsor]))->assertForbidden();
        $this->assertModelExists($sponsor);

        $this->get(route('dashboard.accounts.festivals.settings', [$account, $edition]))
            ->assertOk()
            ->assertDontSee(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]), false);
    }

    public function test_festival_manager_can_manage_sponsors_without_being_the_owner(): void
    {
        [$account, $edition] = $this->festival();
        $manager = User::factory()->create();
        $account->users()->attach($manager->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivals->value],
        ]);

        $this->actingAs($manager)->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), [
            ...$this->payload(),
            'logo' => UploadedFile::fake()->image('manager.png'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $edition->sponsors()->count());
    }

    public function test_disabled_module_blocks_sponsor_routes_and_changes(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $sponsor = FestivalSponsor::factory()->for($edition)->create();
        $account->update(['enable_festivals' => false]);
        $this->actingAs($owner);

        $this->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]))->assertNotFound();
        $this->get(route('dashboard.accounts.festivals.sponsors.create', [$account, $edition]))->assertNotFound();
        $this->get(route('dashboard.accounts.festivals.sponsors.edit', [$account, $edition, $sponsor]))->assertNotFound();
        $this->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), $this->payload())->assertNotFound();
        $this->put(route('dashboard.accounts.festivals.sponsors.update', [$account, $edition, $sponsor]), $this->payload())->assertNotFound();
        $this->delete(route('dashboard.accounts.festivals.sponsors.destroy', [$account, $edition, $sponsor]))->assertNotFound();
        $this->assertModelExists($sponsor);
    }

    public function test_reversed_edition_purchase_preserves_read_access_and_blocks_mutations(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $sponsor = FestivalSponsor::factory()->for($edition)->create();
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'status' => FestivalEditionPurchaseStatus::PaymentReversed,
        ]);
        $this->actingAs($owner);

        $this->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]))->assertOk();
        $this->post(route('dashboard.accounts.festivals.sponsors.store', [$account, $edition]), $this->payload())->assertStatus(423);
        $this->put(route('dashboard.accounts.festivals.sponsors.update', [$account, $edition, $sponsor]), $this->payload())->assertStatus(423);
        $this->delete(route('dashboard.accounts.festivals.sponsors.destroy', [$account, $edition, $sponsor]))->assertStatus(423);
        $this->assertModelExists($sponsor);
    }

    public function test_sponsor_list_filters_and_paginates_with_query_parameters(): void
    {
        [$account, $edition, $owner] = $this->festival();
        FestivalSponsor::factory()->for($edition)->onAllPages()->count(21)->sequence(
            fn ($sequence): array => ['name' => 'Target sponsor '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)],
        )->create();
        FestivalSponsor::factory()->for($edition)->create(['name' => 'Target main page only']);
        FestivalSponsor::factory()->for($edition)->onAllPages()->create(['name' => 'Different search match']);

        $route = route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition, 'q' => 'Target', 'visibility' => 'all_pages']);
        $response = $this->actingAs($owner)->get($route)->assertOk();
        $sponsors = $response->viewData('sponsors');
        $this->assertInstanceOf(LengthAwarePaginator::class, $sponsors);
        $this->assertSame(21, $sponsors->total());
        $this->assertCount(20, $sponsors->items());
        $this->assertStringContainsString('q=Target', $sponsors->url(2));
        $this->assertStringContainsString('visibility=all_pages', $sponsors->url(2));
        $this->assertNotContains('Target main page only', $sponsors->pluck('name')->all());
        $this->assertNotContains('Different search match', $sponsors->pluck('name')->all());

        $this->get($sponsors->url(2))->assertOk()->assertSee('Target sponsor 20');
        $mainPageResponse = $this->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition, 'visibility' => 'main_page']))
            ->assertOk();
        $this->assertSame(['Target main page only'], $mainPageResponse->viewData('sponsors')->pluck('name')->all());
    }

    public function test_sponsor_admin_text_is_escaped_and_navigation_is_last_in_settings(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $sponsor = FestivalSponsor::factory()->for($edition)->create([
            'name' => '<script>unsafe sponsor</script>',
            'short_description' => '<img src=x onerror=alert(1)>',
        ]);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]))
            ->assertOk()
            ->assertSee($sponsor->name)
            ->assertSee($sponsor->short_description)
            ->assertDontSee($sponsor->name, false)
            ->assertDontSee($sponsor->short_description, false);
        $this->assertStringContainsString('data-lucide="handshake"', $response->getContent());
        $this->assertGreaterThan(
            strpos($response->getContent(), 'href="'.route('dashboard.accounts.festivals.settings.fees', [$account, $edition]).'"'),
            strpos($response->getContent(), 'href="'.route('dashboard.accounts.festivals.settings.sponsors', [$account, $edition]).'"'),
        );
    }

    /** @return array{name: string, url: string, short_description: string, giveaway_description: string} */
    private function payload(): array
    {
        return [
            'name' => 'Creative Partner',
            'url' => 'https://example.com/sponsor',
            'short_description' => 'Thoughtful gifts for performers.',
            'giveaway_description' => 'Prize vouchers for the audience favourite.',
        ];
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
