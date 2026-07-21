<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaasBillingLegalTermsTest extends TestCase
{
    public function test_english_terms_explain_the_location_based_subscription_lifecycle(): void
    {
        $this->get('/terms.en.html')
            ->assertOk()
            ->assertSeeText('30-day full-feature trial')
            ->assertSeeText('without providing a card')
            ->assertSeeText('annual prepayment with a 10% discount')
            ->assertSeeText('seven-day grace period')
            ->assertSeeText('at least 30 days notice')
            ->assertSeeText('commercial relationship between the studio and its own customer');
    }

    public function test_ukrainian_terms_explain_the_location_based_subscription_lifecycle(): void
    {
        $this->get('/terms.ua.html')
            ->assertOk()
            ->assertSeeText('30-денний повнофункціональний пробний період')
            ->assertSeeText('без додавання картки')
            ->assertSeeText('річну передоплату зі знижкою 10%')
            ->assertSeeText('семиденний пільговий період')
            ->assertSeeText('щонайменше за 30 днів')
            ->assertSeeText('комерційними відносинами між студією та її клієнтом');
    }
}
