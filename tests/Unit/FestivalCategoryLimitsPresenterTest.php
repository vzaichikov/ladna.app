<?php

namespace Tests\Unit;

use App\Models\FestivalCategory;
use App\Support\Festivals\FestivalCategoryLimitsPresenter;
use Tests\TestCase;

class FestivalCategoryLimitsPresenterTest extends TestCase
{
    public function test_it_presents_the_screenshot_limits_in_ukrainian(): void
    {
        app()->setLocale('uk');
        $category = new FestivalCategory([
            'min_members' => 1,
            'max_members' => 1,
            'min_age' => 18,
            'max_age' => null,
            'min_duration_seconds' => 150,
            'max_duration_seconds' => 195,
        ]);

        $limits = app(FestivalCategoryLimitsPresenter::class)->present($category);

        $this->assertSame('1 учасник', $limits['participants']);
        $this->assertSame('Вік 18+', $limits['age']);
        $this->assertSame('2:30–3:15 хв', $limits['duration']);

        $this->blade('<dl><x-festivals.category-limit-chips :category="$category" /></dl>', compact('category'))
            ->assertSee('1 учасник')
            ->assertSee('Вік 18+')
            ->assertSee('2:30–3:15 хв')
            ->assertDontSee('150–195 с');
    }

    public function test_it_presents_equal_limits_as_single_values_in_english(): void
    {
        app()->setLocale('en');
        $category = new FestivalCategory([
            'min_members' => 2,
            'max_members' => 2,
            'min_age' => 18,
            'max_age' => 18,
            'min_duration_seconds' => 60,
            'max_duration_seconds' => 60,
        ]);

        $this->assertSame([
            'participants' => '2 participants',
            'age' => 'Age 18',
            'duration' => '1:00 min',
        ], app(FestivalCategoryLimitsPresenter::class)->present($category));
    }

    public function test_it_presents_maximum_only_limits_without_placeholder_dashes(): void
    {
        app()->setLocale('en');
        $category = new FestivalCategory([
            'min_members' => 2,
            'max_members' => 100,
            'min_age' => null,
            'max_age' => 17,
            'min_duration_seconds' => null,
            'max_duration_seconds' => 9,
        ]);

        $this->assertSame([
            'participants' => '2–100 participants',
            'age' => 'Age up to 17',
            'duration' => 'Up to 0:09 min',
        ], app(FestivalCategoryLimitsPresenter::class)->present($category));
    }
}
