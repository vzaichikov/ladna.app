<?php

namespace Tests\Feature;

use App\Actions\Festivals\ReconcileFestivalEntrancePasses;
use App\Enums\FestivalNotificationType;
use App\Models\Account;
use App\Models\FestivalAdmissionType;
use App\Models\FestivalCategory;
use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Models\FestivalNotification;
use App\Models\FestivalParticipant;
use App\Models\FestivalPortalUser;
use App\Models\FestivalSeries;
use App\Models\FestivalTicketOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FestivalPortalTicketsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
    }

    public function test_registrant_can_view_and_download_current_passes_while_inactive_passes_are_read_only(): void
    {
        [$account, $edition, $portalUser, $participant, $entry] = $this->acceptedPerformer();
        app(ReconcileFestivalEntrancePasses::class)->reconcileEdition($edition);
        $pass = $participant->entrancePasses()->firstOrFail();

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.tickets.index', $account->slug))
            ->assertOk()
            ->assertSee($pass->code)
            ->assertSee(__('app.festival_participant_pass'))
            ->assertSee(route('festival.portal.tickets.passes.pdf', [$account->slug, $edition]), false);

        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.tickets.passes.pdf', [$account->slug, $edition]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($portalUser, 'festival')
            ->post(route('festival.portal.tickets.passes.email', [$account->slug, $edition]))
            ->assertRedirect();
        $notification = FestivalNotification::query()
            ->where('festival_portal_user_id', $portalUser->id)
            ->where('type', FestivalNotificationType::EntrancePassesIssued->value)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(route('festival.portal.tickets.index', $account->slug), $notification->payload['action_url']);
        $this->assertArrayNotHasKey('token', $notification->payload);
        $this->assertArrayNotHasKey('token_hash', $notification->payload);

        $entry->update(['status' => 'withdrawn']);
        $this->actingAs($portalUser, 'festival')
            ->get(route('festival.portal.tickets.index', $account->slug))
            ->assertOk()
            ->assertSee(__('app.festival_pass_inactive'))
            ->assertDontSee(route('festival.portal.tickets.passes.pdf', [$account->slug, $edition]), false);
    }

    public function test_friend_checkout_is_attributed_to_authenticated_registrant_and_keeps_recipient_delivery_flow(): void
    {
        [$account, $edition, $purchaser] = $this->festival();
        $admissionType = FestivalAdmissionType::factory()->for($edition)->create([
            'account_id' => $account->id,
            'inventory' => 5,
            'price_cents' => 0,
        ]);

        $this->actingAs($purchaser, 'festival')
            ->get(route('public.festivals.show', [$account->slug, $edition->slug, 'friends' => 1]))
            ->assertOk()
            ->assertSee('name="friends" value="1"', false)
            ->assertSee('value="'.e($purchaser->email).'"', false);

        $response = $this->actingAs($purchaser, 'festival')->post(route('public.festivals.admission.store', [$account->slug, $edition->slug]), [
            'friends' => '1',
            'buyer_name' => 'Friendly Recipient',
            'buyer_email' => 'friend@example.test',
            'buyer_email_confirmation' => 'friend@example.test',
            'buyer_phone' => '+380501112233',
            'items' => [$admissionType->id => 1],
            'terms' => '1',
        ]);

        $order = FestivalTicketOrder::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]));
        $this->assertSame($purchaser->id, $order->purchaser_festival_portal_user_id);
        $this->assertSame('friend@example.test', $order->buyer_email);
        $this->assertNotSame($purchaser->id, $order->festival_portal_user_id);
        $this->assertSame(1, $order->tickets()->count());

        $this->actingAs($purchaser, 'festival')
            ->get(route('festival.portal.tickets.index', [$account->slug, 'tab' => 'friends']))
            ->assertOk()
            ->assertSee('Friendly Recipient')
            ->assertSee($order->order_id)
            ->assertSee(route('public.festival-orders.show', [$account->slug, $order->access_token_encrypted]), false);
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser} */
    private function festival(): array
    {
        $account = Account::factory()->create(['enable_festivals' => true]);
        $edition = FestivalEdition::factory()->published()->for(FestivalSeries::factory()->for($account))->create(['account_id' => $account->id]);
        $portalUser = FestivalPortalUser::factory()->for($account)->create();

        return [$account, $edition, $portalUser];
    }

    /** @return array{Account, FestivalEdition, FestivalPortalUser, FestivalParticipant, FestivalEntry} */
    private function acceptedPerformer(): array
    {
        [$account, $edition, $portalUser] = $this->festival();
        $category = FestivalCategory::factory()->for($edition)->create(['account_id' => $account->id]);
        $entry = FestivalEntry::factory()->for($category)->create([
            'account_id' => $account->id,
            'festival_edition_id' => $edition->id,
            'festival_portal_user_id' => $portalUser->id,
            'status' => 'accepted',
        ]);
        $participant = FestivalParticipant::factory()->for($portalUser)->create(['account_id' => $account->id]);
        $entry->participants()->attach($participant->id, ['account_id' => $account->id, 'sort_order' => 0]);

        return [$account, $edition, $portalUser, $participant, $entry];
    }
}
