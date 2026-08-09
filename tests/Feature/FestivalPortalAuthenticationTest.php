<?php

namespace Tests\Feature;

use App\Actions\Festivals\FestivalMagicLink;
use App\Mail\FestivalPortalMail;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FestivalLoginToken;
use App\Models\FestivalPortalUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FestivalPortalAuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_magic_link_is_hashed_one_time_account_bound_and_creates_no_customer(): void
    {
        Mail::fake();
        $account = Account::factory()->create(['enable_festivals' => true]);
        $customerCount = Customer::query()->count();

        app(FestivalMagicLink::class)->issue($account, ' Coach@Example.COM ', '127.0.0.1');

        $portalUser = FestivalPortalUser::query()->whereBelongsTo($account)->firstOrFail();
        $token = FestivalLoginToken::query()->whereBelongsTo($account)->firstOrFail();
        $this->assertSame('coach@example.com', $portalUser->email_normalized);
        $this->assertSame(64, strlen($token->token_hash));
        $this->assertSame($customerCount, Customer::query()->count());

        $mail = null;
        Mail::assertQueued(FestivalPortalMail::class, function (FestivalPortalMail $queued) use (&$mail): bool {
            $mail = $queued;

            return true;
        });
        $url = $mail->actionUrl;
        $rawToken = basename(parse_url($url, PHP_URL_PATH));
        $this->assertNotSame($rawToken, $token->token_hash);

        $this->get($url)->assertRedirect(route('festival.portal.dashboard', $account->slug));
        $this->assertAuthenticatedAs($portalUser, 'festival');

        $this->post(route('festival.portal.logout', $account->slug))->assertRedirect(route('festival.login', $account->slug));
        $this->get($url)->assertSessionHasErrors('token');
    }

    public function test_same_email_has_separate_festival_identities_in_different_accounts(): void
    {
        Mail::fake();
        $first = Account::factory()->create(['enable_festivals' => true]);
        $second = Account::factory()->create(['enable_festivals' => true]);
        app(FestivalMagicLink::class)->issue($first, 'same@example.com', '127.0.0.1');
        app(FestivalMagicLink::class)->issue($second, 'same@example.com', '127.0.0.1');

        $this->assertSame(2, FestivalPortalUser::query()->where('email_normalized', 'same@example.com')->count());
        $this->assertNotSame($first->festivalPortalUsers()->firstOrFail()->id, $second->festivalPortalUsers()->firstOrFail()->id);
    }

    public function test_expired_magic_link_and_suspended_account_are_rejected(): void
    {
        Mail::fake();
        $account = Account::factory()->create(['enable_festivals' => true]);
        app(FestivalMagicLink::class)->issue($account, 'expired@example.com', '127.0.0.1');
        $token = FestivalLoginToken::query()->whereBelongsTo($account)->firstOrFail();
        $token->update(['expires_at' => now()->subMinute()]);
        $account->update(['status' => 'suspended']);
        $this->get(route('festival.login', $account->slug))->assertNotFound();
        $account->update(['status' => 'active']);

        $this->expectException(ValidationException::class);
        app(FestivalMagicLink::class)->consume($account, 'not-the-hashed-token');
    }

    public function test_incomplete_profile_is_gated_and_cross_account_session_returns_not_found(): void
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $other = Account::factory()->create(['enable_festivals' => true]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create(['registrant_type' => null, 'phone' => null]);

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.dashboard', $account->slug))
            ->assertRedirect(route('festival.portal.profile.edit', $account->slug));

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.dashboard', $other->slug))
            ->assertNotFound();
    }

    public function test_magic_link_request_is_throttled_with_generic_response(): void
    {
        Mail::fake();
        $account = Account::factory()->create(['enable_festivals' => true]);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('festival.login.request', $account->slug), ['email' => 'rate@example.com'])->assertRedirect();
        }

        $this->post(route('festival.login.request', $account->slug), ['email' => 'rate@example.com'])->assertTooManyRequests();
    }
}
