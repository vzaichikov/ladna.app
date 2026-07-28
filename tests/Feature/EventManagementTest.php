<?php

namespace Tests\Feature;

use App\Actions\SaveEvent;
use App\Enums\AccountRole;
use App\Enums\EventStatus;
use App\Enums\StudioPermission;
use App\Http\Requests\SaveEventRequest;
use App\Http\Requests\SaveEventTicketTypeRequest;
use App\Http\Requests\StoreEventOrderRequest;
use App\Models\Account;
use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\User;
use App\Support\ScheduleOccupancy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_event_frontend_copy_uses_plain_bilingual_language_and_field_names(): void
    {
        $english = require lang_path('en/app.php');
        $ukrainian = require lang_path('uk/app.php');

        $this->assertSame('Events', $english['event_one_time_activities']);
        $this->assertSame('Події', $ukrainian['event_one_time_activities']);
        $this->assertSame('Event page', $english['event_landing']);
        $this->assertSame('Сторінка події', $ukrainian['event_landing']);
        $this->assertSame('Early bird', $english['event_early_bird']);
        $this->assertSame('Early bird', $ukrainian['event_early_bird']);
        $this->assertStringNotContainsString('One-time activities', implode(' ', array_filter($english, 'is_string')));
        $this->assertStringNotContainsString('Разові активності', implode(' ', array_filter($ukrainian, 'is_string')));

        app()->setLocale('uk');

        $this->assertSame('Місце', (new SaveEventRequest)->attributes()['venue_kind']);
        $this->assertSame('Квота Early bird', (new SaveEventTicketTypeRequest)->attributes()['early_bird_quota']);
        $this->assertSame('Імʼя', (new StoreEventOrderRequest)->attributes()['buyer_name']);
    }

    public function test_default_role_permissions_follow_event_boundaries(): void
    {
        $this->assertContains(StudioPermission::ManageEvents, AccountRole::Manager->defaultPermissions());
        $this->assertContains(StudioPermission::CheckInEventTickets, AccountRole::Manager->defaultPermissions());
        $this->assertContains(StudioPermission::CheckInEventTickets, AccountRole::Receptionist->defaultPermissions());
        $this->assertNotContains(StudioPermission::ManageEvents, AccountRole::Receptionist->defaultPermissions());
        $this->assertNotContains(StudioPermission::CheckInEventTickets, AccountRole::Trainer->defaultPermissions());
    }

    public function test_event_routes_are_tenant_scoped(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->for($otherAccount)->create();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.edit', [$account, $event]))
            ->assertNotFound();
    }

    public function test_event_create_uses_ladna_fields_and_separate_venue_groups(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        Room::factory()->for($account)->for($location)->create();
        $account->addOwner($owner);

        $response = $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.create', $account))
            ->assertOk()
            ->assertSee('data-event-form', false)
            ->assertSee('data-event-venue-fields="studio"', false)
            ->assertSee('data-event-venue-fields="external"', false)
            ->assertSee('data-event-location', false)
            ->assertSee('data-event-room-card', false);

        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $controls = (new \DOMXPath($document))->query('//form[@data-event-form]//*[self::input or self::select or self::textarea]');

        foreach ($controls as $control) {
            if (in_array($control->getAttribute('type'), ['hidden', 'radio', 'checkbox'], true)) {
                continue;
            }

            $this->assertStringContainsString('crm-field', $control->getAttribute('class'), "Unstyled event control: {$control->nodeName}[name={$control->getAttribute('name')}]");
        }
    }

    public function test_published_event_management_pages_offer_a_copyable_public_link(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->published()->for($account)->create();
        $publicEventUrl = route('public.events.show', [$account->slug, $event->slug]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.edit', [$account, $event]))
            ->assertOk()
            ->assertSee('data-copy-button', false)
            ->assertSee('data-copy-value="'.$publicEventUrl.'"', false)
            ->assertSee(__('app.copy_link'));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.index', $account))
            ->assertOk()
            ->assertSee('data-copy-value="'.$publicEventUrl.'"', false);
    }

    public function test_draft_event_does_not_offer_a_public_link_before_publication(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $event = Event::factory()->for($account)->create();
        $publicEventUrl = route('public.events.show', [$account->slug, $event->slug]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.events.edit', [$account, $event]))
            ->assertOk()
            ->assertDontSee('data-copy-value="'.$publicEventUrl.'"', false)
            ->assertDontSee('href="'.$publicEventUrl.'"', false);
    }

    public function test_external_event_discards_submitted_studio_venue_values(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $account->addOwner($owner);

        $response = $this->actingAs($owner)->post(route('dashboard.accounts.events.store', $account), [
            'title' => 'External UX event',
            'summary' => 'An event outside the studio.',
            'venue_kind' => 'external',
            'location_id' => $location->id,
            'room_ids' => [$room->id],
            'external_venue_name' => 'City Gallery',
            'external_address' => '10 Test Street',
            'starts_at' => now($account->timezone)->addMonth()->format('Y-m-d\TH:i'),
            'ends_at' => now($account->timezone)->addMonth()->addHours(2)->format('Y-m-d\TH:i'),
            'timezone' => $account->timezone,
        ]);

        $event = $account->events()->where('title', 'External UX event')->firstOrFail();

        $response->assertRedirect(route('dashboard.accounts.events.edit', [$account, $event]));
        $this->assertNull($event->location_id);
        $this->assertSame('City Gallery', $event->external_venue_name);
        $this->assertCount(0, $event->rooms);
    }

    public function test_publishing_rejects_a_selected_room_with_an_existing_class(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $room = Room::factory()->for($account)->for($location)->create();
        $event = Event::factory()->for($account)->create([
            'venue_kind' => 'studio',
            'location_id' => $location->id,
            'starts_at' => now()->addDays(5),
            'ends_at' => now()->addDays(5)->addHours(2),
        ]);
        $event->rooms()->syncWithPivotValues([$room->id], ['account_id' => $account->id]);
        EventTicketType::factory()->for($account)->for($event)->create();
        ScheduledClass::factory()->for($account)->for($location)->for($room)->create([
            'starts_at' => $event->starts_at->copy()->addMinutes(30),
            'ends_at' => $event->ends_at->copy()->addMinutes(30),
        ]);

        $this->expectException(ValidationException::class);
        app(SaveEvent::class)->publish($account, $event);
    }

    public function test_external_event_can_publish_without_rooms(): void
    {
        $account = Account::factory()->create();
        $event = Event::factory()->for($account)->create();
        EventTicketType::factory()->for($account)->for($event)->create();

        $published = app(SaveEvent::class)->publish($account, $event);

        $this->assertSame(EventStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
    }

    public function test_published_event_blocks_only_its_selected_room_for_manual_and_generated_occupancy(): void
    {
        $account = Account::factory()->create();
        $location = Location::factory()->for($account)->create();
        $selectedRoom = Room::factory()->for($account)->for($location)->create();
        $otherRoom = Room::factory()->for($account)->for($location)->create();
        $event = Event::factory()->published()->for($account)->create([
            'venue_kind' => 'studio',
            'location_id' => $location->id,
        ]);
        $event->rooms()->syncWithPivotValues([$selectedRoom->id], ['account_id' => $account->id]);
        $occupancy = app(ScheduleOccupancy::class);

        $this->assertTrue($occupancy->hasEventRoomConflict(
            $account,
            $selectedRoom->id,
            $event->starts_at->copy()->addMinutes(15),
            $event->ends_at->copy()->subMinutes(15),
        ));
        $this->assertFalse($occupancy->hasEventRoomConflict(
            $account,
            $otherRoom->id,
            $event->starts_at->copy()->addMinutes(15),
            $event->ends_at->copy()->subMinutes(15),
        ));
        $this->assertTrue($occupancy->hasInternalClassConflict(
            $account,
            $selectedRoom->id,
            [],
            $event->starts_at,
            $event->ends_at,
        ));

        $this->expectException(ValidationException::class);
        $occupancy->assertAvailable(
            $account,
            $selectedRoom->id,
            [],
            $event->starts_at,
            $event->ends_at,
        );
    }
}
