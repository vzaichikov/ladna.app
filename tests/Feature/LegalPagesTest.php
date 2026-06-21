<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    public function test_legal_pages_are_public_and_render_required_content(): void
    {
        $pages = [
            '/terms.en.html' => [
                'Terms of Service',
                'FOP Zaychykov V.S.',
                'Taxpayer ID',
                '3197615355',
                'info@ladna.app',
                'privacy.en.html',
            ],
            '/terms.ua.html' => [
                'Угода користувача',
                'ФОП Зайчиков В.С.',
                'ІПН',
                '3197615355',
                'info@ladna.app',
                'privacy.ua.html',
            ],
            '/privacy.en.html' => [
                'Privacy Policy',
                'FOP Zaychykov V.S.',
                'Taxpayer ID',
                '3197615355',
                'info@ladna.app',
                'terms.en.html',
            ],
            '/privacy.ua.html' => [
                'Політика конфіденційності',
                'ФОП Зайчиков В.С.',
                'ІПН',
                '3197615355',
                'info@ladna.app',
                'terms.ua.html',
            ],
        ];

        foreach ($pages as $path => $expectedValues) {
            $response = $this->get($path);

            $response->assertStatus(200);

            foreach ($expectedValues as $expectedValue) {
                $response->assertSee($expectedValue, false);
            }
        }
    }

    public function test_public_footer_links_to_legal_pages_and_changelog(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('terms.ua.html', false);
        $response->assertSee('privacy.ua.html', false);
        $response->assertSee('changelog.ua.html', false);
    }
}
