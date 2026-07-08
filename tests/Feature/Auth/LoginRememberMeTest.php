<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class LoginRememberMeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_internal_login_remember_me_cookie_lasts_at_least_ninety_days(): void
    {
        $user = User::factory()->create([
            'password' => 'correct-password',
        ]);

        $response = $this->post(route('login', absolute: false), [
            'email' => $user->email,
            'password' => 'correct-password',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('dashboard.index', absolute: false));
        $this->assertAuthenticatedAs($user, 'web');

        $rememberCookie = collect($response->headers->getCookies())
            ->first(fn (Cookie $cookie): bool => $cookie->getName() === Auth::guard('web')->getRecallerName());

        $this->assertInstanceOf(Cookie::class, $rememberCookie);
        $this->assertGreaterThanOrEqual(now()->addDays(90)->timestamp, $rememberCookie->getExpiresTime());
    }
}
