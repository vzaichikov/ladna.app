<?php

namespace Tests\Feature;

use App\Actions\Festivals\SaveFestivalEdition;
use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FestivalFoundationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_festival_capability_is_disabled_by_default_and_hides_all_surfaces(): void
    {
        $account = Account::factory()->create();
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->assertFalse($account->enable_festivals);
        $this->get(route('public.festivals.index', $account->slug))->assertNotFound();
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', $account))->assertNotFound();
    }

    public function test_default_permissions_keep_festival_finance_and_judging_restricted(): void
    {
        $manager = AccountRole::Manager->defaultPermissions();
        $receptionist = AccountRole::Receptionist->defaultPermissions();

        $this->assertContains(StudioPermission::ManageFestivals, $manager);
        $this->assertContains(StudioPermission::ManageFestivalRegistrations, $manager);
        $this->assertContains(StudioPermission::ManageFestivalSchedule, $manager);
        $this->assertContains(StudioPermission::CheckInFestivalTickets, $manager);
        $this->assertNotContains(StudioPermission::ManageFestivalFinance, $manager);
        $this->assertNotContains(StudioPermission::JudgeFestivals, $manager);
        $this->assertContains(StudioPermission::CheckInFestivalTickets, $receptionist);
        $this->assertNotContains(StudioPermission::ManageFestivals, $receptionist);
    }

    public function test_edition_creation_copies_series_defaults_once_and_public_calendar_is_tenant_scoped(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en', 'default_currency' => 'USD']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $series = FestivalSeries::factory()->for($account)->create([
            'defaults' => ['stages' => [['name' => 'Main stage']]],
        ]);
        $edition = app(SaveFestivalEdition::class)->execute($account, [
            'festival_series_id' => $series->id,
            'title' => 'Pole Art Kyiv 2027',
            'status' => 'published',
            'registration_status' => 'open',
            'summary' => 'Independent Festival edition.',
            'description_html' => '<p>Public information</p><script>alert(1)</script>',
            'rules_html' => '<p>Rules</p>',
            'venue_name' => 'Arts Hall',
            'venue_address' => 'Kyiv',
            'timezone' => 'Europe/Kyiv',
            'starts_at' => now('Europe/Kyiv')->addMonth()->format('Y-m-d H:i:s'),
            'ends_at' => now('Europe/Kyiv')->addMonth()->addDay()->format('Y-m-d H:i:s'),
            'age_reference_date' => now()->addMonth()->toDateString(),
            'registration_opens_at' => now()->subDay()->format('Y-m-d H:i:s'),
            'registration_closes_at' => now()->addWeeks(2)->format('Y-m-d H:i:s'),
            'max_entries_per_participant' => 2,
        ], $owner);

        $this->assertCount(1, $edition->stages);
        $this->assertSame(2, $edition->max_entries_per_participant);
        $this->assertSame('USD', $edition->currency);
        $series->update(['defaults' => ['stages' => [['name' => 'Changed later']]]]);
        $this->assertSame('Main stage', $edition->stages()->firstOrFail()->name);
        $this->assertStringNotContainsString('<script', (string) $edition->description_html);

        $this->get(route('public.festivals.index', $account->slug))->assertOk()->assertSee($edition->title);
        $this->get(route('public.festivals.show', [$account->slug, $edition->slug]))->assertOk()->assertSee('Public information');

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $this->get(route('public.festivals.show', [$otherAccount->slug, $edition->slug]))->assertNotFound();
    }

    public function test_staff_edition_routes_return_not_found_across_accounts(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['enable_festivals' => true]);
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $account->addOwner($owner);
        $edition = FestivalEdition::factory()->for(FestivalSeries::factory()->for($otherAccount))->create(['account_id' => $otherAccount->id]);

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $edition]))->assertNotFound();
    }
}
