<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\Location;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicEventCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_studio_landing_shows_published_current_events_between_maps_and_contacts(): void
    {
        $account = Account::factory()->create([
            'default_language' => 'en',
            'support_phone_url' => '+380501234567',
        ]);
        $mapUrl = 'https://www.google.com/maps?output=embed&q=Events';
        Location::factory()->for($account)->create(['google_maps_embed_url' => $mapUrl]);
        $ongoing = Event::factory()->published()->for($account)->create([
            'title' => 'Ongoing Studio Event',
            'summary' => 'A current event summary.',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
        $upcoming = Event::factory()->published()->for($account)->create([
            'title' => 'Upcoming Studio Event',
            'summary' => 'An upcoming event summary.',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
        ]);
        Event::factory()->published()->for($account)->create([
            'title' => 'Past Studio Event',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        Event::factory()->for($account)->create(['title' => 'Draft Studio Event']);
        Event::factory()->for($account)->create([
            'status' => EventStatus::Cancelled,
            'title' => 'Cancelled Studio Event',
        ]);

        $this->get(route('public.studio', $account->slug))
            ->assertOk()
            ->assertSee('data-public-events-rail', false)
            ->assertSee($ongoing->title)
            ->assertSee($ongoing->summary)
            ->assertSee($upcoming->title)
            ->assertSee($upcoming->summary)
            ->assertSee(route('public.events.index', $account->slug), false)
            ->assertSeeInOrder([
                $mapUrl,
                'data-public-events-rail',
                __('app.public_contact_title', ['studio' => $account->name]),
            ], false)
            ->assertDontSee('Past Studio Event')
            ->assertDontSee('Draft Studio Event')
            ->assertDontSee('Cancelled Studio Event');

        $this->get(route('public.events.show', [$account->slug, $ongoing->slug]))
            ->assertOk()
            ->assertSee(__('app.event_sales_closed'))
            ->assertDontSee('name="buyer_name"', false);
    }

    public function test_public_event_catalog_paginates_current_and_past_published_events_only(): void
    {
        $account = Account::factory()->create(['default_language' => 'en']);
        $otherAccount = Account::factory()->create(['default_language' => 'en']);

        foreach (range(1, 10) as $number) {
            Event::factory()->published()->for($account)->create([
                'slug' => sprintf('current-event-%02d', $number),
                'title' => sprintf('Current Event %02d', $number),
                'starts_at' => now()->addDays($number),
                'ends_at' => now()->addDays($number)->addHours(2),
            ]);
        }

        $past = Event::factory()->published()->for($account)->create([
            'title' => 'Published Past Event',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
        Event::factory()->for($account)->create(['title' => 'Hidden Draft Event']);
        Event::factory()->for($account)->create([
            'status' => EventStatus::Cancelled,
            'title' => 'Hidden Cancelled Event',
        ]);
        Event::factory()->published()->for($otherAccount)->create(['title' => 'Other Studio Event']);

        $this->get(route('public.events.index', $account->slug))
            ->assertOk()
            ->assertSee(__('app.events_upcoming_public'))
            ->assertSee('Current Event 01')
            ->assertDontSee('Current Event 10')
            ->assertSee(route('public.events.index', ['accountSlug' => $account->slug, 'page' => 2]), false)
            ->assertDontSee($past->title)
            ->assertDontSee('Hidden Draft Event')
            ->assertDontSee('Hidden Cancelled Event')
            ->assertDontSee('Other Studio Event');

        $this->get(route('public.events.index', ['accountSlug' => $account->slug, 'page' => 2]))
            ->assertOk()
            ->assertSee('Current Event 10');

        $pastResponse = $this->get(route('public.events.index', [
            'accountSlug' => $account->slug,
            'tab' => 'past',
        ]))
            ->assertOk()
            ->assertSee(__('app.events_past_public'))
            ->assertSee($past->title)
            ->assertDontSee('Current Event 01')
            ->assertDontSee('Hidden Draft Event')
            ->assertDontSee('Hidden Cancelled Event')
            ->assertDontSee('Other Studio Event');

        $document = new \DOMDocument;
        @$document->loadHTML($pastResponse->getContent());
        $headerLocaleSwitchers = (new \DOMXPath($document))
            ->query('//*[@data-public-studio-header]//*[@data-customer-locale-switcher]');

        $this->assertSame(0, $headerLocaleSwitchers->length);
    }
}
