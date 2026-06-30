<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::get('/_test/errors/403', function (): void {
                abort(403);
            });

            Route::get('/_test/errors/404', function (): void {
                abort(404);
            });

            Route::get('/_test/errors/500', function (): void {
                throw new RuntimeException('Expected test server error.');
            });
        });
    }

    public function test_unmatched_error_page_defaults_to_english_without_session_locale(): void
    {
        $this->get('/_test/errors/missing')
            ->assertNotFound()
            ->assertSee('We could not find that page')
            ->assertSee('assets/brand/errors/404-not-found-cutout.png', false)
            ->assertDontSee('Ми не знайшли цю сторінку');
    }

    public function test_error_pages_use_session_locale_when_available(): void
    {
        $this->withSession(['locale' => 'uk'])
            ->get('/_test/errors/403')
            ->assertForbidden()
            ->assertSee('Цей розділ захищено')
            ->assertSee('assets/brand/errors/403-forbidden-cutout.png', false)
            ->assertSee('На головну');

        $this->withSession(['locale' => 'uk'])
            ->get('/_test/errors/404')
            ->assertNotFound()
            ->assertSee('Ми не знайшли цю сторінку')
            ->assertSee('assets/brand/errors/404-not-found-cutout.png', false)
            ->assertSee('На головну');
    }

    public function test_server_error_page_renders_branding_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);

        $this->withSession(['locale' => 'en'])
            ->get('/_test/errors/500')
            ->assertStatus(500)
            ->assertSee('Something needs a quick repair')
            ->assertSee('assets/brand/errors/500-server-error-cutout.png', false)
            ->assertSee('Back to home');
    }
}
