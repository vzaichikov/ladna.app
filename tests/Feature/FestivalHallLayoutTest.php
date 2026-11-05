<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalEdition;
use App\Models\FestivalEditionPurchase;
use App\Models\FestivalSeries;
use App\Models\FestivalTariffPackage;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\Festivals\FestivalSeatMapPng;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FestivalHallLayoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_finance_can_publish_a_layout_and_disable_seating_without_losing_seat_identity(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $type = $this->assignedType($edition);
        $payload = $this->layout($type);

        $this->assertFalse($edition->assigned_seating_enabled);
        $page = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.hall-layout.edit', [$account, $edition]))
            ->assertOk()
            ->assertSee(__('app.festival_seating_admin.title'));
        $this->assertSame(1, preg_match('/<script type="application\/json" data-hall-config>(.*?)<\/script>/s', $page->getContent(), $matches));
        $config = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(__('app.festival_seating_admin.seat_count'), $config['text']['seat_count'] ?? null);
        $this->assertSame(__('app.festival_seating_admin.selection'), $config['text']['selection'] ?? null);
        $this->putJson(route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]), $payload)
            ->assertOk();

        $this->assertTrue($edition->refresh()->assigned_seating_enabled);
        $this->assertSame(1, $type->refresh()->inventory);
        $seat = $edition->seats()->sole();

        $this->patchJson(route('dashboard.accounts.festivals.hall-layout.mode', [$account, $edition]), ['enabled' => false])
            ->assertOk();
        $this->assertFalse($edition->refresh()->assigned_seating_enabled);
        $this->assertSame($seat->id, $edition->seats()->sole()->id);
    }

    public function test_layout_rejects_duplicate_labels_and_another_editions_ticket_type(): void
    {
        [$account, $edition, $owner] = $this->festival();
        [, $anotherEdition] = $this->festival();
        $payload = $this->layout($this->assignedType($anotherEdition));
        $this->actingAs($owner)
            ->putJson(route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout');

        $payload = $this->layout($this->assignedType($edition));
        $payload['seats'][] = [...$payload['seats'][0], 'x' => 3200];
        $this->putJson(route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('layout');
        $this->assertSame(0, $edition->seats()->count());
    }

    public function test_live_hold_prevents_topology_edits_disabling_and_blocking_the_seat(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $payload = $this->layout($this->assignedType($edition));
        $parameters = [$account, $edition];

        $this->actingAs($owner)
            ->putJson(route('dashboard.accounts.festivals.hall-layout.update', $parameters), $payload)
            ->assertOk();
        $seat = $edition->seats()->sole();
        $seat->forceFill(['hold_key' => hash('sha256', 'session'), 'hold_expires_at' => now()->addMinute()])->save();

        $this->putJson(route('dashboard.accounts.festivals.hall-layout.update', $parameters), $payload)->assertUnprocessable();
        $this->patchJson(route('dashboard.accounts.festivals.hall-layout.mode', $parameters), ['enabled' => false])->assertUnprocessable();
        $this->patchJson(route('dashboard.accounts.festivals.hall-layout.block', $parameters), ['seat_ids' => [$seat->id], 'blocked' => true])->assertUnprocessable();

        $seat->forceFill(['hold_expires_at' => now()->subSecond()])->save();
        $this->patchJson(route('dashboard.accounts.festivals.hall-layout.block', $parameters), ['seat_ids' => [$seat->id], 'blocked' => true])->assertOk();
        $this->assertTrue($seat->refresh()->is_blocked);
    }

    public function test_publishing_checks_the_package_capacity_across_assigned_and_unassigned_types(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $plan = SubscriptionPlan::factory()->create(['currency' => 'UAH']);
        $package = FestivalTariffPackage::factory()->create([
            'subscription_plan_id' => $plan->id,
            'max_tickets' => 2,
        ]);
        FestivalEditionPurchase::factory()->create([
            'account_id' => $account->id,
            'subscription_plan_id' => $plan->id,
            'festival_tariff_package_id' => $package->id,
            'festival_edition_id' => $edition->id,
        ]);
        $unassigned = FestivalAdmissionType::factory()->for($edition)->create(['account_id' => $account->id, 'inventory' => 2]);
        $assigned = $this->assignedType($edition);
        $url = route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]);

        $this->actingAs($owner)->putJson($url, $this->layout($assigned))->assertJsonValidationErrors('layout');
        $this->assertFalse($edition->refresh()->assigned_seating_enabled);
        $this->assertSame(0, $assigned->refresh()->inventory);
        $this->assertSame(0, $edition->seats()->count());

        $unassigned->update(['inventory' => 1]);
        $this->putJson($url, $this->layout($assigned))->assertOk();
        $this->assertSame(1, $assigned->refresh()->inventory);
    }

    public function test_upload_review_and_publish_store_only_the_clean_background(): void
    {
        Storage::fake('public');
        [$account, $edition, $owner] = $this->festival();
        $type = $this->assignedType($edition);
        $parser = app(FestivalSeatMapPng::class);
        $parameters = [$account, $edition];

        $example = $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.hall-layout.template', [...$parameters, 'example']))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->assertSame($parser->template(example: true), $example->getContent());

        $response = $this->post(
            route('dashboard.accounts.festivals.hall-layout.import', $parameters),
            ['floor_image' => UploadedFile::fake()->createWithContent('floor.png', $parser->template(example: true))],
            ['Accept' => 'application/json'],
        )->assertOk()->assertJsonCount(20, 'seats');
        $payload = $this->layout($type);
        $payload['floors'][0]['background'] = $response->json('background');

        $this->putJson(route('dashboard.accounts.festivals.hall-layout.update', $parameters), $payload)->assertOk();
        $path = substr($edition->refresh()->seating_floors[0]['background'], strlen('/storage/'));
        Storage::disk('public')->assertExists($path);
        $this->assertSame([], $parser->parse(Storage::disk('public')->get($path))['seats']);
    }

    public function test_assigned_ticket_types_get_their_inventory_from_the_hall_layout(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $this->actingAs($owner)->post(route('dashboard.accounts.festivals.admission-types.store', [$account, $edition]), [
            'delivery_mode' => 'assigned_venue',
            'name' => 'Reserved stalls',
            'price' => '250.50',
            'inventory' => 123,
            'max_per_order' => 10,
            'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $type = $edition->admissionTypes()->sole();
        $this->assertTrue($type->is_assigned_seating);
        $this->assertSame(0, $type->inventory);
        $this->assertSame(25050, $type->price_cents);
    }

    public function test_hall_layout_is_scoped_to_the_edition_and_finance_permission(): void
    {
        [$account, $edition, $owner] = $this->festival();
        [, $anotherEdition] = $this->festival();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.festivals.hall-layout.edit', [$account, $anotherEdition]))
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.accounts.festivals.hall-layout.edit', [$account, $edition]))
            ->assertForbidden();
    }

    public function test_sections_and_venue_blocks_can_be_saved_renamed_and_removed_without_replacing_seats(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $payload = $this->layout($this->assignedType($edition));
        $payload['publish'] = false;
        $payload['floors'][0]['sections'] = ['Stalls', 'Empty balcony'];
        $payload['floors'][0]['blocks'] = [[
            'id' => 'main-stage', 'kind' => 'stage', 'label' => 'Main stage',
            'x' => 5000, 'y' => 1000, 'width' => 6000, 'height' => 1200,
        ]];
        $url = route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]);

        $this->actingAs($owner)->putJson($url, $payload)->assertOk()
            ->assertJsonPath('floors.0.sections.1', 'Empty balcony')
            ->assertJsonPath('floors.0.blocks.0.label', 'Main stage');
        $seat = $edition->seats()->sole();
        $payload['seats'][0]['id'] = $seat->id;
        $payload['seats'][0]['section'] = 'Front';
        $payload['floors'][0]['sections'] = ['Front'];
        $payload['floors'][0]['blocks'] = [];

        $this->putJson($url, $payload)->assertOk()->assertJsonPath('floors.0.blocks', []);
        $this->assertSame($seat->id, $edition->seats()->sole()->id);
        $this->assertSame('Front', $seat->refresh()->section);
        $this->assertSame(['Front'], $edition->refresh()->seating_floors[0]['sections']);
        $this->assertFalse($edition->assigned_seating_enabled);
    }

    public function test_floor_metadata_rejects_ambiguous_sections_and_invalid_blocks_atomically(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $payload = $this->layout($this->assignedType($edition));
        $floor = $payload['floors'][0];
        $block = ['id' => 'bar', 'kind' => 'bar', 'label' => 'Bar', 'x' => 1000, 'y' => 1000, 'width' => 1200, 'height' => 800];
        $invalid = [
            ['sections' => ['Stalls', 'STALLS']],
            ['sections' => ['Balcony']],
            ['blocks' => [$block, $block]],
            ['blocks' => [[...$block, 'x' => 200]]],
            ['blocks' => [[...$block, 'height' => 0]]],
            ['blocks' => [[...$block, 'kind' => 'script']]],
            ['blocks' => [[...$block, 'html' => '<script>']]],
        ];

        foreach ($invalid as $metadata) {
            $payload['floors'][0] = [...$floor, ...$metadata];
            $this->actingAs($owner)->putJson(route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]), $payload)
                ->assertUnprocessable();
            $this->assertSame(0, $edition->seats()->count());
        }
    }

    public function test_section_names_and_block_ids_are_scoped_to_each_floor(): void
    {
        [$account, $edition, $owner] = $this->festival();
        $payload = $this->layout($this->assignedType($edition));
        $payload['floors'][0]['sections'] = ['Stalls'];
        $payload['floors'][0]['blocks'] = [[
            'id' => 'bar', 'kind' => 'bar', 'label' => 'Bar',
            'x' => 5000, 'y' => 1000, 'width' => 2000, 'height' => 800,
        ]];
        $payload['floors'][] = [...$payload['floors'][0], 'id' => 'upper', 'label' => 'Upper floor'];

        $this->actingAs($owner)->putJson(route('dashboard.accounts.festivals.hall-layout.update', [$account, $edition]), $payload)
            ->assertOk()->assertJsonPath('floors.1.blocks.0.id', 'bar');
    }

    /** @return array{Account, FestivalEdition, User} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $edition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create(['account_id' => $account->id]);
        $owner = User::factory()->create();
        $account->addOwner($owner);

        return [$account, $edition, $owner];
    }

    private function assignedType(FestivalEdition $edition): FestivalAdmissionType
    {
        return FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $edition->account_id,
            'is_assigned_seating' => true,
            'inventory' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private function layout(FestivalAdmissionType $type): array
    {
        $floor = (string) Str::uuid();

        return [
            'publish' => true,
            'floors' => [['id' => $floor, 'label' => 'Ground floor', 'width' => 900, 'height' => 600, 'stage' => 'Main stage', 'background' => null]],
            'seats' => [[
                'floor_key' => $floor,
                'section' => 'Stalls',
                'group_kind' => 'row',
                'group_label' => 'A',
                'seat_number' => '1',
                'x' => 2200,
                'y' => 3600,
                'festival_admission_type_id' => $type->id,
                'is_blocked' => false,
            ]],
        ];
    }
}
