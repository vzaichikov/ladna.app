<?php

namespace Tests\Feature;

use App\Enums\FestivalPortalRole;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramFestivalPortalLink;
use App\Support\Festivals\FestivalTelegramIdentityLinker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalTelegramIdentityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_one_series_authorization_links_registrant_and_guest_without_merging_roles(): void
    {
        [$account, $series, $installation] = $this->festival();
        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '7711001',
            '7711001',
            '+380501112233',
            ['first_name' => 'Marta', 'last_name' => 'Guest', 'username' => 'marta', 'language_code' => 'uk'],
        );
        $guest = FestivalPortalUser::factory()->guest()->for($account)->create([
            'telegram_user_id' => '7711001',
            'phone' => '+380509998877',
            'phone_normalized' => '+380509998877',
        ]);
        app(FestivalTelegramIdentityLinker::class)->linkPortalUser($linked['authorization'], $guest);

        $this->assertSame(FestivalPortalRole::Registrant, $linked['registrant']->role);
        $this->assertSame(FestivalPortalRole::Guest, $guest->role);
        $this->assertNotSame($linked['registrant']->id, $guest->id);
        $this->assertSame('7711001', $linked['registrant']->telegram_user_id);
        $this->assertSame('7711001', $guest->telegram_user_id);
        $this->assertSame(2, $linked['authorization']->festivalPortalLinks()->count());
    }

    public function test_festival_telegram_link_factory_keeps_account_and_series_installation_consistent(): void
    {
        $link = TelegramFestivalPortalLink::factory()->create();

        $this->assertSame($link->account_id, $link->portalUser->account_id);
        $this->assertSame($link->account_id, $link->authorization->account_id);
        $this->assertSame($link->account_id, $link->authorization->installation->account_id);
        $this->assertSame('festival_series', $link->authorization->installation->scope_type);
        $this->assertSame(TelegramBotProfile::Festival, $link->authorization->profile);
    }

    public function test_same_telegram_id_is_unique_inside_each_role_but_allowed_across_roles(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        FestivalPortalUser::factory()->for($account)->create([
            'telegram_user_id' => '8811001',
            'phone' => '+380501111111',
            'phone_normalized' => '+380501111111',
        ]);
        FestivalPortalUser::factory()->guest()->for($account)->create([
            'telegram_user_id' => '8811001',
            'phone' => '+380502222222',
            'phone_normalized' => '+380502222222',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        FestivalPortalUser::factory()->for($account)->create([
            'telegram_user_id' => '8811001',
            'phone' => '+380503333333',
            'phone_normalized' => '+380503333333',
        ]);
    }

    public function test_identity_linker_rejects_phone_telegram_collisions_and_cross_account_links(): void
    {
        [$account, $series, $installation] = $this->festival();
        FestivalPortalUser::factory()->for($account)->create([
            'telegram_user_id' => '9911001',
            'phone' => '+380504444444',
            'phone_normalized' => '+380504444444',
        ]);

        try {
            app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
                $series,
                $installation,
                '9911001',
                '9911001',
                '+380505555555',
                ['first_name' => 'Wrong'],
            );
            $this->fail('Expected a Telegram identity collision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contact', $exception->errors());
        }

        $linked = app(FestivalTelegramIdentityLinker::class)->authorizeRegistrant(
            $series,
            $installation,
            '6611001',
            '6611001',
            '+380506666666',
            ['first_name' => 'Valid'],
        );
        $otherAccount = Account::factory()->create(['enable_festivals' => true]);
        $otherGuest = FestivalPortalUser::factory()->guest()->for($otherAccount)->create();

        $this->expectException(ValidationException::class);
        app(FestivalTelegramIdentityLinker::class)->linkPortalUser($linked['authorization'], $otherGuest);
    }

    /** @return array{Account, FestivalSeries, TelegramBotInstallation} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true, 'default_language' => 'uk']);
        $series = FestivalSeries::factory()->for($account)->create();
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival,
            'is_enabled' => true,
        ]);

        return [$account, $series, $installation];
    }
}
