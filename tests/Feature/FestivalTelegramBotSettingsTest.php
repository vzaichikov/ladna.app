<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\StudioPermission;
use App\Enums\TelegramBotProfile;
use App\Models\Account;
use App\Models\FestivalSeries;
use App\Models\TelegramBotInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FestivalTelegramBotSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_series_page_shows_last_four_only_and_requires_settings_permission_for_token_changes(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create();
        $installation = TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $series->id,
            'profile' => TelegramBotProfile::Festival,
            'bot_username' => 'series_festival_bot',
            'encrypted_token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            'token_last_four' => 'BCDE',
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.series.edit', [$account, $series]))
            ->assertOk()
            ->assertSee('@series_festival_bot')
            ->assertSee('••••BCDE')
            ->assertDontSee($installation->tokenValue())
            ->assertSee('name="token"', false);

        $festivalManager = User::factory()->create();
        $account->users()->attach($festivalManager->id, [
            'role' => AccountRole::Trainer->value,
            'permissions' => [StudioPermission::ManageFestivals->value],
        ]);
        $this->actingAs($festivalManager)
            ->get(route('dashboard.accounts.festivals.series.edit', [$account, $series]))
            ->assertOk()
            ->assertSee(__('app.festival_telegram_token_permission_required'))
            ->assertDontSee('name="token"', false);

        $this->actingAs($festivalManager)
            ->put(route('dashboard.accounts.festivals.series.telegram-bot.update', [$account, $series]), [
                'token' => '987654321:abcdefghijklmnopqrstuvwxyz_ABCDE',
            ])
            ->assertForbidden();
    }

    public function test_owner_connects_an_independent_series_bot_with_commands_and_mini_app_menu(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/getMe')) {
                return Http::response(['ok' => true, 'result' => ['id' => 8741001, 'username' => 'festival_series_bot']]);
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
        $account = Account::factory()->create(['enable_festivals' => true]);
        $series = FestivalSeries::factory()->for($account)->create(['slug' => 'evolution-series']);
        $owner = User::factory()->create();
        $account->addOwner($owner);
        $token = '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE';

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.series.telegram-bot.update', [$account, $series]), ['token' => $token])
            ->assertRedirect(route('dashboard.accounts.festivals.series.edit', [$account, $series]))
            ->assertSessionHas('status', __('app.telegram_webhook_registered'));

        $installation = TelegramBotInstallation::query()
            ->where('scope_type', 'festival_series')
            ->where('scope_id', $series->id)
            ->where('profile', TelegramBotProfile::Festival->value)
            ->sole();
        $this->assertSame($account->id, $installation->account_id);
        $this->assertSame('8741001', $installation->bot_id);
        $this->assertSame('festival_series_bot', $installation->bot_username);
        $this->assertTrue($installation->is_enabled);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['allowed_updates'] === ['message', 'callback_query', 'my_chat_member']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setChatMenuButton')
            && data_get($request['menu_button'], 'web_app.url') === route('public.festival-telegram.show', [$account->slug, $series->slug]));
        $languages = collect(Http::recorded())
            ->map(fn (array $record): Request => $record[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands'))
            ->map(fn (Request $request): mixed => $request['language_code'])
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['en', 'uk'], $languages);
    }

    public function test_one_series_bot_cannot_be_reused_for_another_series(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['id' => 8741002, 'username' => 'shared_festival_bot'],
        ])]);
        $account = Account::factory()->create(['enable_festivals' => true]);
        $firstSeries = FestivalSeries::factory()->for($account)->create();
        $secondSeries = FestivalSeries::factory()->for($account)->create();
        TelegramBotInstallation::factory()->for($account)->create([
            'scope_type' => 'festival_series',
            'scope_id' => $firstSeries->id,
            'profile' => TelegramBotProfile::Festival,
            'bot_id' => '8741002',
        ]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.festivals.series.telegram-bot.update', [$account, $secondSeries]), [
                'token' => '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE',
            ])
            ->assertSessionHasErrors(['festival_telegram_bot' => __('app.telegram_bot_already_connected')]);

        $this->assertFalse(TelegramBotInstallation::query()->where('scope_type', 'festival_series')->where('scope_id', $secondSeries->id)->exists());
    }
}
