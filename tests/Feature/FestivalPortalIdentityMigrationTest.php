<?php

namespace Tests\Feature;

use App\Enums\FestivalPortalRole;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class FestivalPortalIdentityMigrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_backfill_classifies_existing_assigned_profiles_as_judges(): void
    {
        [$account, $edition] = $this->festival();
        $portalUser = FestivalPortalUser::factory()->for($account)->create(['role' => FestivalPortalRole::Registrant]);
        FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'display_name' => 'Legacy Judge',
            'is_active' => true,
        ]);

        $this->roleBackfillMigration()->up();

        $this->assertSame(FestivalPortalRole::Judge, $portalUser->refresh()->role);
    }

    public function test_backfill_aborts_before_changes_when_one_profile_has_participant_and_judge_usage(): void
    {
        [$account, $edition] = $this->festival();
        $portalUser = FestivalPortalUser::factory()->for($account)->create(['role' => FestivalPortalRole::Registrant]);
        FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        FestivalJudgeAssignment::query()->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'display_name' => 'Conflicting Legacy User',
            'is_active' => true,
        ]);

        try {
            $this->roleBackfillMigration()->up();
            $this->fail('The dual-use role backfill did not abort.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $portalUser->id, $exception->getMessage());
        }

        $this->assertSame(FestivalPortalRole::Registrant, $portalUser->refresh()->role);
        $this->assertTrue(Schema::hasTable('festival_otp_challenges'));
        $this->assertFalse(Schema::hasTable('festival_login_tokens'));
    }

    public function test_backfill_aborts_on_account_scoped_normalized_phone_collisions(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'country_code' => 'UA']);
        $first = FestivalPortalUser::factory()->for($account)->create([
            'phone' => '050 111 22 33',
            'phone_normalized' => null,
        ]);
        $second = FestivalPortalUser::factory()->for($account)->create([
            'phone' => '+38 (050) 111-22-33',
            'phone_normalized' => null,
        ]);

        try {
            $this->roleBackfillMigration()->up();
            $this->fail('The normalized phone collision did not abort the backfill.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString((string) $first->id, $exception->getMessage());
            $this->assertStringContainsString((string) $second->id, $exception->getMessage());
        }

        $this->assertNull($first->refresh()->phone_normalized);
        $this->assertNull($second->refresh()->phone_normalized);
    }

    public function test_email_phone_and_google_identities_are_unique_per_account_but_reusable_across_accounts(): void
    {
        $firstAccount = Account::factory()->create(['enable_festivals' => true]);
        $secondAccount = Account::factory()->create(['enable_festivals' => true]);
        FestivalPortalUser::factory()->for($firstAccount)->create([
            'email' => 'identity@example.com',
            'email_normalized' => 'identity@example.com',
            'phone' => '+380501112233',
            'phone_normalized' => '+380501112233',
            'google_id' => 'google-identity',
        ]);
        FestivalPortalUser::factory()->for($secondAccount)->create([
            'email' => 'identity@example.com',
            'email_normalized' => 'identity@example.com',
            'phone' => '+380501112233',
            'phone_normalized' => '+380501112233',
            'google_id' => 'google-identity',
        ]);

        $duplicates = [
            [
                'email' => 'identity@example.com',
                'email_normalized' => 'identity@example.com',
                'phone' => '+380509998871',
                'phone_normalized' => '+380509998871',
                'google_id' => 'different-google-1',
            ],
            [
                'email' => 'different-phone@example.com',
                'email_normalized' => 'different-phone@example.com',
                'phone' => '+380501112233',
                'phone_normalized' => '+380501112233',
                'google_id' => 'different-google-2',
            ],
            [
                'email' => 'different-google@example.com',
                'email_normalized' => 'different-google@example.com',
                'phone' => '+380509998873',
                'phone_normalized' => '+380509998873',
                'google_id' => 'google-identity',
            ],
        ];

        foreach ($duplicates as $attributes) {
            try {
                FestivalPortalUser::factory()->for($firstAccount)->create($attributes);
                $this->fail('A duplicate account-scoped Festival identity was accepted.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_otp_schema_and_magic_link_contract_match_the_new_authentication_boundary(): void
    {
        $this->assertTrue(Schema::hasTable('festival_otp_challenges'));
        $this->assertFalse(Schema::hasTable('festival_login_tokens'));

        $columns = Schema::getColumnListing('festival_otp_challenges');
        foreach (['account_id', 'role', 'phone', 'code_hash', 'expires_at', 'consumed_at', 'attempts', 'send_count'] as $column) {
            $this->assertContains($column, $columns);
        }

        $portalColumns = Schema::getColumnListing('festival_portal_users');
        foreach (['role', 'is_active', 'password', 'google_id', 'phone_normalized', 'phone_verified_at'] as $column) {
            $this->assertContains($column, $portalColumns);
        }
    }

    /** @return array{Account, FestivalEdition} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $edition = FestivalEdition::factory()->published()->for($series)->create(['account_id' => $account->id]);

        return [$account, $edition];
    }

    private function roleBackfillMigration(): object
    {
        return require database_path('migrations/2026_08_12_070811_backfill_festival_portal_user_roles_and_phones.php');
    }
}
