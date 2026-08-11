<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\FestivalEditionPurchaseStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FestivalHubTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_routes_use_ids_and_public_slugs_follow_the_edition_lifecycle(): void
    {
        [$account, $owner, $series] = $this->ownerFestival();

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.store', $account), $this->editionData($series, [
            'title' => 'Мій фестиваль 2027',
        ]))->assertRedirect();

        $edition = FestivalEdition::query()->whereBelongsTo($account)->where('title', 'Мій фестиваль 2027')->firstOrFail();
        $this->assertSame('miy-festyval-2027', $edition->slug);
        $this->assertStringEndsWith('/festivals/'.$edition->id, route('dashboard.accounts.festivals.show', [$account, $edition]));
        $this->assertStringEndsWith('/festivals/'.$edition->slug, route('public.festivals.show', [$account->slug, $edition->slug]));

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.store', $account), $this->editionData($series, [
            'title' => 'Мій фестиваль 2027',
        ]))->assertRedirect();
        $this->assertDatabaseHas('festival_editions', [
            'account_id' => $account->id,
            'slug' => 'miy-festyval-2027-2',
        ]);

        $oldSlug = $edition->slug;
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $this->editionData($series, [
            'title' => 'Новий фестиваль',
        ]))->assertRedirect();
        $edition->refresh();
        $this->assertSame('novyy-festyval', $edition->slug);
        $this->assertNotSame($oldSlug, $edition->slug);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $this->editionData($series, [
            'title' => 'Новий фестиваль',
            'status' => 'published',
            'registration_status' => 'open',
        ]))->assertRedirect();
        $publishedSlug = $edition->refresh()->slug;

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $this->editionData($series, [
            'title' => 'Нова маркетингова назва',
            'status' => 'published',
            'registration_status' => 'open',
        ]))->assertRedirect();
        $this->assertSame($publishedSlug, $edition->refresh()->slug);

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.show', [$account, $oldSlug]))->assertNotFound();
        $this->get(route('public.festivals.show', [$account->slug, $publishedSlug]))->assertOk()->assertSee('Нова маркетингова назва');
    }

    public function test_hub_tabs_are_paginated_isolated_and_permission_aware(): void
    {
        [$account, $owner, $primarySeries] = $this->ownerFestival();

        foreach (range(1, 13) as $number) {
            FestivalEdition::factory()->for($primarySeries)->create([
                'account_id' => $account->id,
                'title' => sprintf('Festival Edition %02d', $number),
                'slug' => sprintf('festival-edition-%02d', $number),
                'starts_at' => now()->addDays($number),
                'ends_at' => now()->addDays($number + 1),
            ]);
        }

        foreach (range(1, 31) as $number) {
            FestivalSeries::factory()->for($account)->create([
                'name' => sprintf('Series Listing %02d', $number),
                'slug' => sprintf('series-listing-%02d', $number),
            ]);
        }
        FestivalSeries::factory()->for($account)->create([
            'name' => 'Series Only Sentinel',
            'slug' => 'series-only-sentinel',
        ]);

        $festivalPage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', $account));
        $festivalPage->assertOk()
            ->assertSee('data-workspace="studio"', false)
            ->assertSee('Festival Edition 13')
            ->assertDontSee('Festival Edition 01')
            ->assertDontSee('Series Only Sentinel')
            ->assertSee(__('app.festival_series_tab'))
            ->assertSee(__('app.festival_payments_tariffs_tab'))
            ->assertDontSee(__('app.festival_entitlements'));

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'festivals_page' => 2,
        ]))->assertOk()->assertSee('Festival Edition 01');

        $seriesPage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'series',
        ]));
        $seriesPage->assertOk()
            ->assertSee('Series Listing 01')
            ->assertDontSee('Series Listing 31')
            ->assertDontSee('Festival Edition 13')
            ->assertDontSee(__('app.festival_entitlements'));
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'series',
            'series_page' => 2,
        ]))->assertOk()->assertSee('Series Listing 31');

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'payments',
        ]))->assertOk()
            ->assertSee(__('app.festival_entitlements'))
            ->assertDontSee('Festival Edition 13')
            ->assertDontSee('Series Only Sentinel');

        foreach ([
            StudioPermission::ManageFestivalRegistrations,
            StudioPermission::ManageFestivalSchedule,
            StudioPermission::ManageFestivalFinance,
            StudioPermission::CheckInFestivalTickets,
            StudioPermission::JudgeFestivals,
        ] as $permission) {
            $staff = $this->staff($account, $permission);
            $this->actingAs($staff)
                ->get(route('dashboard.accounts.festivals.index', $account))
                ->assertOk()
                ->assertDontSee(__('app.festival_series_tab'))
                ->assertDontSee(__('app.festival_payments_tariffs_tab'));
            $this->actingAs($staff)->get(route('dashboard.accounts.festivals.index', [
                'account' => $account,
                'tab' => 'series',
            ]))->assertForbidden();
            $this->actingAs($staff)->get(route('dashboard.accounts.festivals.index', [
                'account' => $account,
                'tab' => 'payments',
            ]))->assertForbidden();
        }
    }

    public function test_hub_default_tab_follows_edition_existence_and_explicit_tabs_win(): void
    {
        $this->assertSame('Payments & tariffs', trans('app.festival_payments_tariffs_tab', [], 'en'));
        $this->assertSame('Оплати й тарифи', trans('app.festival_payments_tariffs_tab', [], 'uk'));

        [$account, $owner, $series] = $this->ownerFestival();

        $paymentsPage = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', $account));
        $paymentsPage->assertOk()
            ->assertViewHas('tab', 'payments')
            ->assertSee(__('app.festival_entitlements'))
            ->assertDontSee(__('app.festivals_empty'));

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'unexpected',
        ]))->assertOk()
            ->assertViewHas('tab', 'payments');

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'festivals',
        ]))->assertOk()
            ->assertSee(__('app.festivals_empty'))
            ->assertDontSee(__('app.festival_entitlements'));

        $manager = User::factory()->create();
        $account->users()->attach($manager->id, ['role' => AccountRole::Manager->value]);
        $this->actingAs($manager)->get(route('dashboard.accounts.festivals.index', $account))
            ->assertOk()
            ->assertViewHas('tab', 'payments')
            ->assertSee(__('app.festival_owner_payment_required'))
            ->assertDontSee('action="'.route('dashboard.accounts.festivals.purchases.store', $account).'"', false);

        $registrationStaff = $this->staff($account, StudioPermission::ManageFestivalRegistrations);
        $this->actingAs($registrationStaff)->get(route('dashboard.accounts.festivals.index', $account))
            ->assertOk()
            ->assertSee(__('app.festivals_empty'))
            ->assertDontSee(__('app.festival_payments_tariffs_tab'));
        $this->actingAs($registrationStaff)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'unexpected',
        ]))->assertOk()
            ->assertViewHas('tab', 'festivals')
            ->assertSee(__('app.festivals_empty'));

        $edition = FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Draft Festival Default Sentinel',
            'status' => 'draft',
        ]);

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', $account))
            ->assertOk()
            ->assertViewHas('tab', 'festivals')
            ->assertSee($edition->title)
            ->assertDontSee(__('app.festival_entitlements'));
        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'unexpected',
        ]))->assertOk()
            ->assertSee($edition->title)
            ->assertDontSee(__('app.festival_entitlements'));

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'payments',
        ]))->assertOk()
            ->assertSee(__('app.festival_entitlements'))
            ->assertDontSee($edition->title);
    }

    public function test_purchase_history_links_only_account_scoped_created_editions(): void
    {
        [$account, $owner, $series] = $this->ownerFestival();
        $linkedEdition = FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Linked Festival Sentinel',
        ]);
        $reversedEdition = FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Reversed Festival Sentinel',
        ]);
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherSeries = FestivalSeries::factory()->for($otherAccount)->create();
        $foreignEdition = FestivalEdition::factory()->for($otherSeries)->create([
            'account_id' => $otherAccount->id,
            'title' => 'Foreign Festival Sentinel',
        ]);
        $purchases = FestivalEditionPurchase::query()->whereBelongsTo($account)->oldest('id')->get();
        $foreignLinkedPackage = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $purchases[0]->subscription_plan_id,
            'name' => 'Foreign-linked Purchase Sentinel',
        ]);
        $unlinkedPackage = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $purchases[0]->subscription_plan_id,
            'name' => 'Unlinked Purchase Sentinel',
        ]);
        $purchases[0]->forceFill([
            'status' => FestivalEditionPurchaseStatus::Redeemed,
            'festival_edition_id' => $linkedEdition->id,
            'redeemed_at' => now(),
            'created_at' => now()->addMinutes(4),
        ])->save();
        $purchases[1]->forceFill([
            'status' => FestivalEditionPurchaseStatus::PaymentReversed,
            'festival_edition_id' => $reversedEdition->id,
            'redeemed_at' => now(),
            'reversed_at' => now(),
            'created_at' => now()->addMinutes(3),
        ])->save();
        $purchases[2]->forceFill([
            'status' => FestivalEditionPurchaseStatus::Redeemed,
            'festival_edition_id' => $foreignEdition->id,
            'festival_tariff_package_id' => $foreignLinkedPackage->id,
            'redeemed_at' => now(),
            'created_at' => now()->addMinutes(2),
        ])->save();
        $unlinkedPurchase = FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $purchases[0]->subscription_plan_id,
            'festival_tariff_package_id' => $unlinkedPackage->id,
            'created_by_user_id' => $owner->id,
            'festival_edition_id' => null,
            'created_at' => now()->addMinute(),
        ]);
        FestivalEditionPurchase::factory()->count(8)->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $purchases[0]->subscription_plan_id,
            'festival_tariff_package_id' => $purchases[0]->festival_tariff_package_id,
            'created_by_user_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.accounts.festivals.index', [
            'account' => $account,
            'tab' => 'payments',
        ]));

        $response->assertOk()
            ->assertSee($linkedEdition->title)
            ->assertSee(route('dashboard.accounts.festivals.show', [$account, $linkedEdition]), false)
            ->assertSee($reversedEdition->title)
            ->assertSee(route('dashboard.accounts.festivals.show', [$account, $reversedEdition]), false)
            ->assertSee('Foreign-linked Purchase Sentinel')
            ->assertDontSee($foreignEdition->title)
            ->assertSee($unlinkedPurchase->package->name)
            ->assertViewHas('festivalPurchases', fn ($festivalPurchases): bool => $festivalPurchases->hasPages()
                && str_contains($festivalPurchases->url(2), 'tab=payments')
                && str_contains($festivalPurchases->url(2), 'purchases_page=2'));
        $this->assertSame(2, substr_count($response->getContent(), __('app.festival_linked_edition')));
    }

    public function test_series_can_be_created_and_edited_without_exposing_a_slug_field(): void
    {
        [$account, $owner] = $this->ownerFestival();

        $this->actingAs($owner)->get(route('dashboard.accounts.festivals.series.create', $account))
            ->assertOk()
            ->assertDontSee('name="slug"', false);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.series.store', $account), [
            'name' => 'Студійна серія',
            'summary' => 'Series summary',
            'organizer_name' => 'Organizer',
            'organizer_email' => 'organizer@example.test',
            'brand_color' => '#10233F',
            'is_active' => '1',
        ])->assertRedirect(route('dashboard.accounts.festivals.index', ['account' => $account, 'tab' => 'series']));

        $series = FestivalSeries::query()->whereBelongsTo($account)->where('name', 'Студійна серія')->firstOrFail();
        $originalSlug = $series->slug;
        $existingEdition = FestivalEdition::factory()->for($series)->create(['account_id' => $account->id]);
        $series->forceFill(['logo_path' => 'festival-series/logo.png', 'defaults' => ['stages' => [['name' => 'Main']]]])->save();

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.series.update', [$account, $series]), [
            'name' => 'Оновлена серія',
            'summary' => 'Updated summary',
        ])->assertRedirect(route('dashboard.accounts.festivals.index', ['account' => $account, 'tab' => 'series']));

        $series->refresh();
        $this->assertSame($originalSlug, $series->slug);
        $this->assertFalse($series->is_active);
        $this->assertSame('festival-series/logo.png', $series->logo_path);
        $this->assertSame(['stages' => [['name' => 'Main']]], $series->defaults);

        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.store', $account), $this->editionData($series))
            ->assertSessionHasErrors('festival_series_id');
        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $existingEdition]), $this->editionData($series, [
            'title' => $existingEdition->title,
        ]))->assertRedirect();

        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherOwner = User::factory()->create();
        $otherAccount->addOwner($otherOwner);
        $this->actingAs($otherOwner)->get(route('dashboard.accounts.festivals.series.edit', [$otherAccount, $series]))->assertNotFound();
    }

    public function test_festival_create_and_edit_forms_group_fields_and_explain_each_section(): void
    {
        [$account, $owner, $series] = $this->ownerFestival();
        $purchase = FestivalEditionPurchase::query()
            ->whereBelongsTo($account)
            ->where('status', 'available')
            ->firstOrFail();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.create', ['account' => $account, 'purchase' => $purchase]))
            ->assertOk()
            ->assertSee(__('app.festival_identity'))
            ->assertSee(__('app.festival_schedule_lifecycle'))
            ->assertSee(__('app.festival_registration_settings'))
            ->assertSee(__('app.festival_venue_access'))
            ->assertSee(__('app.festival_public_content'))
            ->assertSee(__('app.festival_series_field_help'))
            ->assertSee(__('app.festival_rules_field_help'));

        $edition = FestivalEdition::factory()->for($series)->create(['account_id' => $account->id]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.edit', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_form_intro'))
            ->assertSee(__('app.festival_registration_status_field_help'))
            ->assertSee(__('app.festival_venue_directions_field_help'));
    }

    public function test_judge_only_staff_see_only_their_assigned_festivals_on_the_hub(): void
    {
        [$account, , $series] = $this->ownerFestival();
        $assignedEdition = FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Assigned Festival Sentinel',
            'slug' => 'assigned-festival-sentinel',
        ]);
        FestivalEdition::factory()->for($series)->create([
            'account_id' => $account->id,
            'title' => 'Unassigned Festival Sentinel',
            'slug' => 'unassigned-festival-sentinel',
        ]);
        $judge = $this->staff($account, StudioPermission::JudgeFestivals);
        FestivalJudgeAssignment::factory()->for($assignedEdition)->for($judge)->create([
            'account_id' => $account->id,
            'is_active' => true,
        ]);

        $this->actingAs($judge)
            ->get(route('dashboard.accounts.festivals.index', $account))
            ->assertOk()
            ->assertSee('Assigned Festival Sentinel')
            ->assertDontSee('Unassigned Festival Sentinel');
    }

    public function test_hero_image_upload_replaces_the_previous_managed_cover_safely(): void
    {
        Storage::fake('public');
        [$account, $owner, $series] = $this->ownerFestival();
        $edition = FestivalEdition::factory()->for($series)->create(['account_id' => $account->id]);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $this->editionData($series, [
            'title' => $edition->title,
            'hero_image' => UploadedFile::fake()->image('first-cover.png', 1600, 900),
        ]))->assertRedirect();

        $firstCover = $edition->coverMedia()->firstOrFail();
        Storage::disk('public')->assertExists($firstCover->path);

        $this->actingAs($owner)->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $this->editionData($series, [
            'title' => $edition->title,
            'hero_image' => UploadedFile::fake()->image('second-cover.jpg', 1600, 900),
        ]))->assertRedirect();

        $secondCover = $edition->coverMedia()->firstOrFail();
        $this->assertNotSame($firstCover->path, $secondCover->path);
        Storage::disk('public')->assertMissing($firstCover->path);
        Storage::disk('public')->assertExists($secondCover->path);
        $this->assertSame(1, $edition->media()->where('is_cover', true)->count());

        $this->actingAs($owner)->from(route('dashboard.accounts.festivals.edit', [$account, $edition]))
            ->put(route('dashboard.accounts.festivals.update', [$account, $edition]), $this->editionData($series, [
                'title' => $edition->title,
                'hero_image' => UploadedFile::fake()->create('unsafe.svg', 10, 'image/svg+xml'),
            ]))
            ->assertRedirect(route('dashboard.accounts.festivals.edit', [$account, $edition]))
            ->assertSessionHasErrors('hero_image');
        Storage::disk('public')->assertExists($secondCover->path);
        $this->assertSame($secondCover->id, $edition->coverMedia()->firstOrFail()->id);
    }

    /** @return array{Account, User, FestivalSeries} */
    private function ownerFestival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'en']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $series = FestivalSeries::factory()->for($account)->create();
        $plan = SubscriptionPlan::factory()->create(['currency' => 'UAH']);
        AccountSubscription::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
        ]);
        $package = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $plan->id,
            'name' => 'Test S '.str()->random(8),
        ]);
        FestivalEditionPurchase::factory()->count(3)->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'created_by_user_id' => $owner->id,
        ]);

        return [$account, $owner, $series];
    }

    /** @return array<string, mixed> */
    private function editionData(FestivalSeries $series, array $overrides = []): array
    {
        return [
            'festival_purchase_id' => FestivalEditionPurchase::query()
                ->where('account_id', $series->account_id)
                ->where('status', 'available')
                ->whereNull('festival_edition_id')
                ->oldest('id')
                ->value('id'),
            'festival_series_id' => $series->id,
            'title' => 'Festival edition',
            'status' => 'draft',
            'registration_status' => 'closed',
            'summary' => 'Festival summary',
            'timezone' => 'Europe/Kyiv',
            'currency' => 'UAH',
            'starts_at' => now('Europe/Kyiv')->addMonth()->format('Y-m-d H:i:s'),
            'ends_at' => now('Europe/Kyiv')->addMonth()->addDay()->format('Y-m-d H:i:s'),
            'age_reference_date' => now('Europe/Kyiv')->addMonth()->toDateString(),
            ...$overrides,
        ];
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
