<?php

namespace Tests\Feature;

use App\Rules\NotReservedPublicSlug;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LocalizationRenderingTest extends TestCase
{
    public function test_application_and_reserved_slug_keys_resolve_in_both_locales(): void
    {
        foreach (['en', 'uk'] as $locale) {
            foreach (['app.customer', 'app.preview', 'app.required', 'app.unknown', 'validation.reserved_public_slug'] as $key) {
                $this->assertTrue(Lang::hasForLocale($key, $locale), "Missing translation [{$key}] for [{$locale}].");
                $this->assertNotSame($key, Lang::get($key, locale: $locale));
            }

            App::setLocale($locale);

            $validator = Validator::make(
                ['slug' => 'app'],
                ['slug' => [new NotReservedPublicSlug]],
            );

            $this->assertSame(__('validation.reserved_public_slug'), $validator->errors()->first('slug'));
        }
    }

    public function test_tailwind_paginator_renders_php_translations_in_both_locales(): void
    {
        $paginator = new LengthAwarePaginator(
            items: range(11, 20),
            total: 30,
            perPage: 10,
            currentPage: 2,
            options: ['path' => '/localized-results'],
        );

        foreach (['en', 'uk'] as $locale) {
            App::setLocale($locale);
            $html = (string) $paginator->links();

            $this->assertStringContainsString('aria-label="'.__('pagination.navigation').'"', $html);
            $this->assertStringContainsString(__('pagination.showing'), $html);
            $this->assertStringContainsString(__('pagination.to'), $html);
            $this->assertStringContainsString(__('pagination.of'), $html);
            $this->assertStringContainsString(__('pagination.results'), $html);
            $this->assertStringContainsString(__('pagination.previous'), $html);
            $this->assertStringContainsString(__('pagination.next'), $html);
            $this->assertStringContainsString('aria-label="'.__('pagination.go_to_page', ['page' => 1]).'"', $html);
            $this->assertStringContainsString('<span class="font-medium">11</span>', $html);
            $this->assertStringContainsString('<span class="font-medium">20</span>', $html);
            $this->assertStringContainsString('<span class="font-medium">30</span>', $html);
        }
    }
}
