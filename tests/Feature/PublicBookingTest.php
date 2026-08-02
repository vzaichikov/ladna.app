<?php

namespace Tests\Feature;

use App\Enums\ClassBookingStatus;
use App\Enums\PublicScheduleView;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassPassPlan;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\Trainer;
use App\Models\TrainerPrivateTimeframe;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PublicBookingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_booking_redirects_to_customer_login_by_default_and_preserves_intended_url(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-login-required-studio',
            'allow_guest_public_booking' => false,
        ]);
        $bookingUrl = route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
        ]);

        $this->get($bookingUrl)
            ->assertRedirect(route('customer.studio.login', $account->slug));

        $this->assertSame($bookingUrl, session('url.intended'));

        Carbon::setTestNow();
    }

    public function test_modal_booking_redirects_guest_login_back_to_the_same_schedule_and_selected_class(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-modal-login-required-studio',
            'public_schedule_view' => PublicScheduleView::CompactBooking->value(),
            'public_group_booking_modal_views' => [PublicScheduleView::CompactBooking->value()],
            'allow_guest_public_booking' => false,
        ]);
        $returnUrl = route('public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'date' => '2026-06-18',
            'trainer' => 12,
        ]);
        $bookingUrl = route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'presentation' => 'modal',
            'return_to' => $returnUrl,
        ]);
        $expectedIntendedUrl = route('public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'date' => '2026-06-18',
            'trainer' => 12,
            'booking' => $scheduledClass->id,
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get($bookingUrl)
            ->assertStatus(409)
            ->assertJson([
                'redirect_url' => route('customer.studio.login', $account->slug),
            ]);

        $this->assertSame($expectedIntendedUrl, session('url.intended'));

        Carbon::setTestNow();
    }

    public function test_authenticated_customer_can_load_and_restore_group_booking_modal_without_studio_header(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'name' => 'Modal Header Must Stay Outside',
            'slug' => 'public-modal-confirmation-studio',
            'public_schedule_view' => PublicScheduleView::CalendarBooking->value(),
            'public_group_booking_modal_views' => [PublicScheduleView::CalendarBooking->value()],
        ]);
        $customer = Customer::factory()->for($account)->create(['name' => 'Modal Customer']);
        $returnUrl = route('public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'display' => 'list',
            'date' => '2026-06-18',
        ]);
        $bookingUrl = route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'presentation' => 'modal',
            'return_to' => $returnUrl,
        ]);

        $this->actingAs($customer, 'customer')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get($bookingUrl)
            ->assertOk()
            ->assertSee('data-public-booking-modal', false)
            ->assertSee('data-public-booking-form', false)
            ->assertSee('name="customer_session_expected" value="1"', false)
            ->assertSee('Public Pole')
            ->assertSee('Modal Customer')
            ->assertDontSee($account->name);

        $this->actingAs($customer, 'customer')
            ->get($returnUrl.'&booking='.$scheduledClass->id)
            ->assertOk()
            ->assertSee('data-auto-open="true"', false)
            ->assertSee('data-public-booking-modal', false);

        Carbon::setTestNow();
    }

    public function test_authenticated_modal_booking_returns_server_rendered_success_with_required_actions(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-modal-success-studio',
            'public_schedule_view' => PublicScheduleView::CompactBooking->value(),
            'public_group_booking_modal_views' => [PublicScheduleView::CompactBooking->value()],
        ]);
        $customer = Customer::factory()->for($account)->create();
        $returnUrl = route('public.schedule', [$account->slug, $location->slug, 'date' => '2026-06-18']);

        $response = $this->actingAs($customer, 'customer')
            ->postJson(route('public.booking.store', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'presentation' => 'modal',
            ]), [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
                'return_to' => $returnUrl,
            ])
            ->assertOk()
            ->assertJsonPath('message', __('app.booking_created'))
            ->assertJsonPath('continue_url', $returnUrl)
            ->assertJsonStructure(['booking_id', 'success_html']);

        $successHtml = (string) $response->json('success_html');

        $this->assertStringContainsString(__('app.go_to_customer_cabinet'), $successHtml);
        $this->assertStringContainsString(__('app.continue_booking'), $successHtml);
        $this->assertStringContainsString(route('customer.dashboard', $account->slug), $successHtml);
        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->whereBelongsTo($customer)->count());

        Carbon::setTestNow();
    }

    public function test_guest_modal_booking_success_links_to_studio_login(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-guest-modal-success-studio',
            'public_schedule_view' => PublicScheduleView::CompactBooking->value(),
            'public_group_booking_modal_views' => [PublicScheduleView::CompactBooking->value()],
            'allow_guest_public_booking' => true,
        ]);
        $returnUrl = route('public.schedule', [$account->slug, $location->slug, 'date' => '2026-06-18']);

        $response = $this->postJson(route('public.booking.store', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'presentation' => 'modal',
        ]), [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'customer_name' => 'Guest Modal Customer',
            'customer_phone' => '+380501112277',
            'return_to' => $returnUrl,
        ])->assertOk();

        $this->assertStringContainsString(
            route('customer.studio.login', $account->slug),
            (string) $response->json('success_html'),
        );
        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->count());

        Carbon::setTestNow();
    }

    public function test_guest_modal_booking_returns_json_validation_errors_without_creating_a_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-guest-modal-validation-studio',
            'public_schedule_view' => PublicScheduleView::CompactBooking->value(),
            'public_group_booking_modal_views' => [PublicScheduleView::CompactBooking->value()],
            'allow_guest_public_booking' => true,
        ]);
        $returnUrl = route('public.schedule', [$account->slug, $location->slug, 'date' => '2026-06-18']);

        $this->postJson(route('public.booking.store', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'presentation' => 'modal',
        ]), [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'return_to' => $returnUrl,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name', 'customer_phone']);

        $this->assertSame(0, ClassBooking::whereBelongsTo($scheduledClass)->count());

        Carbon::setTestNow();
    }

    public function test_modal_load_returns_recovery_url_when_the_class_becomes_unavailable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-modal-unavailable-studio',
            'public_schedule_view' => PublicScheduleView::CompactBooking->value(),
            'public_group_booking_modal_views' => [PublicScheduleView::CompactBooking->value()],
            'allow_guest_public_booking' => true,
        ], [
            'capacity' => 0,
        ]);
        $returnUrl = route('public.schedule', [$account->slug, $location->slug, 'date' => '2026-06-18']);
        $bookingUrl = route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'presentation' => 'modal',
            'return_to' => $returnUrl,
        ]);
        $expectedRecoveryUrl = route('public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'date' => '2026-06-18',
            'booking' => $scheduledClass->id,
        ]);

        $this->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->get($bookingUrl)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('app.no_available_group_slots'))
            ->assertJsonPath('redirect_url', $expectedRecoveryUrl);

        Carbon::setTestNow();
    }

    public function test_expired_customer_session_redirects_modal_submission_to_login_even_when_guest_booking_is_enabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-modal-expired-session-studio',
            'public_schedule_view' => PublicScheduleView::CompactBooking->value(),
            'public_group_booking_modal_views' => [PublicScheduleView::CompactBooking->value()],
            'allow_guest_public_booking' => true,
        ]);
        $returnUrl = route('public.schedule', [$account->slug, $location->slug, 'date' => '2026-06-18']);
        $expectedIntendedUrl = route('public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'date' => '2026-06-18',
            'booking' => $scheduledClass->id,
        ]);

        $this->postJson(route('public.booking.store', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'presentation' => 'modal',
        ]), [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'customer_session_expected' => true,
            'return_to' => $returnUrl,
        ])
            ->assertStatus(409)
            ->assertJsonPath('redirect_url', route('customer.studio.login', $account->slug));

        $this->assertSame($expectedIntendedUrl, session('url.intended'));
        $this->assertSame(0, ClassBooking::whereBelongsTo($scheduledClass)->count());

        Carbon::setTestNow();
    }

    public function test_group_booking_modal_is_opt_in_for_each_schedule_view_and_disabled_in_embeds(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-modal-view-options-studio',
        ]);
        $customer = Customer::factory()->for($account)->create();

        foreach (PublicScheduleView::cases() as $scheduleView) {
            $account->update([
                'public_schedule_view' => $scheduleView->value(),
                'public_group_booking_modal_views' => [$scheduleView->value()],
            ]);
            $query = match ($scheduleView) {
                PublicScheduleView::Classic => ['period' => 'week'],
                PublicScheduleView::CompactBooking => ['date' => '2026-06-18'],
                PublicScheduleView::CalendarBooking => ['display' => 'list', 'date' => '2026-06-18'],
            };

            $this->actingAs($customer, 'customer')
                ->get(route('public.schedule', [$account->slug, $location->slug, ...$query]))
                ->assertOk()
                ->assertSee('data-public-booking-open', false)
                ->assertSee('presentation=modal', false);

            $this->actingAs($customer, 'customer')
                ->get(route('public.schedule.embed', [$account->slug, $location->slug, ...$query]))
                ->assertOk()
                ->assertDontSee('data-public-booking-open', false)
                ->assertDontSee('presentation=modal', false)
                ->assertSee('scheduled_class_id='.$scheduledClass->id, false);
        }

        Carbon::setTestNow();
    }

    public function test_booking_confirmation_uses_customer_locale_switcher_in_footer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-booking-topbar-studio',
            'studio_rules_html' => '<p>Booking rules</p>',
            'public_offer_html' => '<p>Booking offer</p>',
        ]);
        $customer = Customer::factory()->for($account)->create();

        $this->actingAs($customer, 'customer')
            ->get(route('public.booking.show', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
            ]))
            ->assertOk()
            ->assertDontSee('data-customer-page-topbar', false)
            ->assertSee('data-customer-locale-switcher', false)
            ->assertSee('data-customer-footer-locale-switcher', false)
            ->assertSee('data-customer-footer-legal-links', false)
            ->assertSee('data-public-rules-footer-link', false)
            ->assertSee('data-public-offer-footer-link', false);

        Carbon::setTestNow();
    }

    public function test_authenticated_customer_can_book_group_class_and_reserve_matching_pass(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass, $classType] = $this->publicGroupClass([
            'slug' => 'public-auth-booking-studio',
        ], [
            'capacity' => 3,
        ]);
        $customer = Customer::factory()->for($account)->create([
            'name' => 'Olena Client',
            'phone' => '+380501112233',
        ]);
        $classPassPlan = ClassPassPlan::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'sessions_count' => 4,
            'is_active' => true,
        ]);
        $classPassPlan->classTypes()->attach($classType);
        $customerClassPass = CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($classPassPlan)
            ->create([
                'sessions_count' => 4,
                'reserved_sessions_count' => 0,
                'used_sessions_count' => 0,
                'purchased_at' => now()->subDay(),
                'usable_until_at' => now()->addDays(30),
            ]);

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
            ])
            ->assertRedirect(route('customer.dashboard', $account->slug))
            ->assertSessionHas('status', __('app.booking_created'));

        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->assertNull($booking->booked_by_user_id);
        $this->assertSame('customer', $booking->booked_by_actor_role);
        $this->assertSame(ClassBookingStatus::Booked, $booking->status);
        $this->assertNotNull($booking->classPassReservation);
        $this->assertSame(1, $customerClassPass->fresh()->reserved_sessions_count);

        Carbon::setTestNow();
    }

    public function test_guest_lite_booking_creates_customer_when_enabled(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-guest-booking-studio',
            'allow_guest_public_booking' => true,
            'country_code' => 'UA',
        ]);

        $this->post(route('public.booking.store', [$account->slug, $location->slug]), [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
            'customer_name' => 'Guest Client',
            'customer_phone' => '050 111 22 44',
        ])
            ->assertRedirect(route('public.schedule', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'kind' => ScheduleKind::GroupClass->value,
                'date' => '2026-06-18',
            ]))
            ->assertSessionHas('status', __('app.booking_created'));

        $customer = Customer::whereBelongsTo($account)->where('phone', '+380501112244')->firstOrFail();
        $booking = ClassBooking::whereBelongsTo($account)->whereBelongsTo($customer)->firstOrFail();

        $this->assertSame('Guest Client', $customer->name);
        $this->assertNull($booking->booked_by_user_id);
        $this->assertSame('public_guest', $booking->booked_by_actor_role);
        $this->assertNull($booking->classPassReservation);

        Carbon::setTestNow();
    }

    public function test_classic_guest_booking_redirect_displays_success_notification(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-classic-guest-booking-studio',
            'public_schedule_view' => PublicScheduleView::Classic->value(),
            'allow_guest_public_booking' => true,
            'country_code' => 'UA',
        ]);

        $this->followingRedirects()
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
                'customer_name' => 'Classic Guest',
                'customer_phone' => '050 111 22 45',
            ])
            ->assertOk()
            ->assertSee(__('app.booking_created'));

        Carbon::setTestNow();
    }

    public function test_legacy_guest_booking_uses_server_validated_phone_without_blur_layout_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-legacy-phone-validation-studio',
            'allow_guest_public_booking' => true,
        ]);
        $bookingUrl = route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
        ]);

        $this->get($bookingUrl)
            ->assertOk()
            ->assertSee('data-phone-mask-validate="false"', false);

        $this->from($bookingUrl)
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
                'customer_name' => 'Invalid Phone Guest',
                'customer_phone' => '123',
            ])
            ->assertRedirect($bookingUrl)
            ->assertSessionHasErrors('customer_phone');

        $this->assertSame(0, Customer::whereBelongsTo($account)->count());
        $this->assertSame(0, ClassBooking::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_guest_post_is_redirected_when_guest_lite_booking_is_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-guest-rejected-studio',
            'allow_guest_public_booking' => false,
        ]);

        $this->post(route('public.booking.store', [$account->slug, $location->slug]), [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
        ])
            ->assertRedirect(route('customer.studio.login', $account->slug));

        $this->assertFalse(ClassBooking::whereBelongsTo($account)->exists());
        $this->assertSame(route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
        ]), session('url.intended'));

        Carbon::setTestNow();
    }

    public function test_group_booking_rejects_full_class_but_duplicate_customer_booking_stays_single(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass] = $this->publicGroupClass([
            'slug' => 'public-duplicate-booking-studio',
        ], [
            'capacity' => 1,
        ]);
        $customer = Customer::factory()->for($account)->create(['phone' => '+380501112245']);
        $otherCustomer = Customer::factory()->for($account)->create(['phone' => '+380501112246']);

        $payload = [
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
        ];

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->whereBelongsTo($customer)->count());

        $this->actingAs($otherCustomer, 'customer')
            ->from(route('public.booking.show', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
            ]))
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertSessionHasErrors('scheduled_class_id');

        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->count());

        Carbon::setTestNow();
    }

    public function test_private_lesson_public_booking_creates_manual_class_and_blocks_slot_collision(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $room] = $this->manualBookingSetup('public-private-slot-studio');
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
            'default_capacity' => 1,
        ]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Nastya']);
        $firstCustomer = Customer::factory()->for($account)->create(['phone' => '+380501112247']);
        $secondCustomer = Customer::factory()->for($account)->create(['phone' => '+380501112248']);
        $payload = [
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'date' => '2026-06-18',
            'starts_at' => '2026-06-18T15:00',
            'class_type_id' => $classType->id,
            'room_id' => $room->id,
            'trainer_id' => $trainer->id,
            'people_count' => 2,
        ];

        $this->actingAs($firstCustomer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $scheduledClass = ScheduledClass::whereBelongsTo($account)->where('title', 'Private 60')->firstOrFail();

        $this->assertSame('public_booking', $scheduledClass->metadata['source']);
        $this->assertSame(ScheduleKind::PrivateLesson, $scheduledClass->classType->schedule_kind);
        $this->assertSame(2, $scheduledClass->capacity);
        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->count());

        $this->actingAs($secondCustomer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->where('title', 'Private 60')->count());
        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->count());

        Carbon::setTestNow();
    }

    public function test_private_lesson_public_booking_rejects_a_customer_schedule_collision(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $privateRoom] = $this->manualBookingSetup('public-private-customer-collision');
        $otherRoom = Room::factory()->for($account)->for($location)->create();
        $privateClassType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $groupClassType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
        ]);
        $privateTrainer = Trainer::factory()->for($account)->create();
        $groupTrainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $existingClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($otherRoom)
            ->for($groupClassType)
            ->for($groupTrainer)
            ->create([
                'starts_at' => '2026-06-18 15:00:00',
                'ends_at' => '2026-06-18 16:00:00',
            ]);
        ClassBooking::factory()
            ->for($account)
            ->for($existingClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Booked->value]);

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-18',
                'starts_at' => '2026-06-18T15:00',
                'class_type_id' => $privateClassType->id,
                'room_id' => $privateRoom->id,
                'trainer_id' => $privateTrainer->id,
                'people_count' => 1,
            ])
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(0, ScheduledClass::whereBelongsTo($account)->whereBelongsTo($privateClassType)->count());

        Carbon::setTestNow();
    }

    public function test_public_private_lesson_rejects_slot_without_trainer_timeframe_when_enabled(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $room] = $this->manualBookingSetup('public-private-timeframe-required-studio');
        $account->update(['trainer_private_timeframes_enabled' => true]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-18',
                'starts_at' => '2026-06-18T15:00',
                'class_type_id' => $classType->id,
                'room_id' => $room->id,
                'trainer_id' => $trainer->id,
            ])
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(0, ScheduledClass::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_public_private_lesson_uses_consecutive_trainer_timeframes_and_selected_free_room(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $room] = $this->manualBookingSetup('public-private-timeframe-valid-studio');
        $account->update(['trainer_private_timeframes_enabled' => true]);
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
            'default_capacity' => 1,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $this->createPublicTrainerTimeframes($account, $trainer, $location, ['15:00', '15:30']);

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-18',
                'starts_at' => '2026-06-18T15:00',
                'class_type_id' => $classType->id,
                'room_id' => $room->id,
                'trainer_id' => $trainer->id,
            ])
            ->assertRedirect(route('customer.dashboard', $account->slug))
            ->assertSessionHas('status', __('app.booking_created'));

        $scheduledClass = ScheduledClass::whereBelongsTo($account)->where('title', 'Private 60')->firstOrFail();

        $this->assertSame($room->id, $scheduledClass->room_id);
        $this->assertSame($trainer->id, $scheduledClass->trainer_id);
        $this->assertSame('public_booking', $scheduledClass->metadata['source']);
        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->whereBelongsTo($customer)->count());

        Carbon::setTestNow();
    }

    public function test_public_private_lesson_rejects_trainer_outside_selected_activity_direction(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $room] = $this->manualBookingSetup('public-private-direction-mismatch-studio');
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $exoticDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Exotic']);
        $classType = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'name' => 'Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $trainer->activityDirections()->sync([$exoticDirection->id => ['account_id' => $account->id]]);
        $customer = Customer::factory()->for($account)->create();

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-18',
                'starts_at' => '2026-06-18T15:00',
                'class_type_id' => $classType->id,
                'activity_direction_id' => $poleDirection->id,
                'room_id' => $room->id,
                'trainer_id' => $trainer->id,
            ])
            ->assertSessionHasErrors('trainer_id');

        $this->assertSame(0, ScheduledClass::whereBelongsTo($account)->count());

        Carbon::setTestNow();
    }

    public function test_public_private_lesson_rejects_incompatible_room_and_accepts_matching_room(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $matchingRoom] = $this->manualBookingSetup('public-private-room-direction-studio');
        $mismatchingRoom = Room::factory()->for($account)->for($location)->create(['capacity' => 1]);
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $exoticDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Exotic']);
        $matchingRoom->activityDirections()->sync([$poleDirection->id => ['account_id' => $account->id]]);
        $mismatchingRoom->activityDirections()->sync([$exoticDirection->id => ['account_id' => $account->id]]);
        $classType = ClassType::factory()->for($account)->create([
            'activity_direction_id' => null,
            'name' => 'Private Direction 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();
        $payload = [
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'date' => '2026-06-18',
            'starts_at' => '2026-06-18T15:00',
            'class_type_id' => $classType->id,
            'activity_direction_id' => $poleDirection->id,
            'room_id' => $mismatchingRoom->id,
            'trainer_id' => $trainer->id,
            'people_count' => 1,
        ];

        $this->actingAs($customer, 'customer')
            ->get(route('public.booking.show', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                ...$payload,
            ]))
            ->assertRedirect(route('public.schedule', [$account->slug, $location->slug]))
            ->assertSessionHasErrors('room_id');

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertSessionHasErrors('room_id');

        $this->assertSame(0, ScheduledClass::whereBelongsTo($account)->count());

        $payload['room_id'] = $matchingRoom->id;

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $this->assertSame(1, ScheduledClass::whereBelongsTo($account)->whereBelongsTo($matchingRoom)->count());

        Carbon::setTestNow();
    }

    public function test_public_private_lesson_uses_class_type_direction_without_requiring_an_explicit_direction(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $room] = $this->manualBookingSetup('public-directed-private-class-type');
        $direction = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $room->activityDirections()->sync([$direction->id => ['account_id' => $account->id]]);
        $classType = ClassType::factory()->for($account)->create([
            'activity_direction_id' => $direction->id,
            'name' => 'Directed Private 60',
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);
        $trainer = Trainer::factory()->for($account)->create();
        $customer = Customer::factory()->for($account)->create();

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::PrivateLesson->value,
                'date' => '2026-06-18',
                'starts_at' => '2026-06-18T15:00',
                'class_type_id' => $classType->id,
                'room_id' => $room->id,
                'trainer_id' => $trainer->id,
                'people_count' => 1,
            ])
            ->assertRedirect(route('customer.dashboard', $account->slug))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(
            1,
            ScheduledClass::whereBelongsTo($account)
                ->whereBelongsTo($room)
                ->whereBelongsTo($classType)
                ->count(),
        );

        Carbon::setTestNow();
    }

    public function test_existing_group_class_in_incompatible_room_remains_publicly_bookable(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $scheduledClass, $classType] = $this->publicGroupClass([
            'slug' => 'public-existing-room-direction-conflict',
        ]);
        $poleDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Pole']);
        $exoticDirection = ActivityDirection::factory()->for($account)->create(['name' => 'Exotic']);
        $classType->update(['activity_direction_id' => $poleDirection->id]);
        $scheduledClass->room->activityDirections()->sync([$exoticDirection->id => ['account_id' => $account->id]]);
        $customer = Customer::factory()->for($account)->create();
        $this->actingAs($customer, 'customer');
        $bookingUrl = route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'scheduled_class_id' => $scheduledClass->id,
        ]);

        foreach (PublicScheduleView::cases() as $scheduleView) {
            $account->update(['public_schedule_view' => $scheduleView->value()]);
            $query = match ($scheduleView) {
                PublicScheduleView::Classic => ['period' => 'week'],
                PublicScheduleView::CompactBooking => ['date' => '2026-06-18'],
                PublicScheduleView::CalendarBooking => ['display' => 'list', 'date' => '2026-06-18'],
            };

            foreach (['public.schedule', 'public.schedule.embed'] as $routeName) {
                $response = $this->get(route($routeName, [
                    'accountSlug' => $account->slug,
                    'locationSlug' => $location->slug,
                    ...$query,
                ]));

                $response
                    ->assertOk()
                    ->assertSee('Public Pole');

                $response->assertSee('schedule/book?schedule_kind=group_class&amp;scheduled_class_id='.$scheduledClass->id, false);
            }
        }

        $this->actingAs($customer, 'customer')
            ->get($bookingUrl)
            ->assertOk();

        $this->actingAs($customer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
            ])
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->whereBelongsTo($customer)->count());

        Carbon::setTestNow();
    }

    public function test_room_rental_public_booking_allows_one_active_customer_per_slot(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-06-17 09:00:00', 'UTC'));

        [$account, $location, $room] = $this->manualBookingSetup('public-room-rental-slot-studio');
        $classType = ClassType::factory()->for($account)->create([
            'name' => 'Rental 60',
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'default_duration_minutes' => 60,
            'default_capacity' => 1,
        ]);
        $direction = ActivityDirection::factory()->for($account)->create();
        $room->activityDirections()->sync([$direction->id => ['account_id' => $account->id]]);
        $firstCustomer = Customer::factory()->for($account)->create(['phone' => '+380501112249']);
        $secondCustomer = Customer::factory()->for($account)->create(['phone' => '+380501112250']);
        $payload = [
            'schedule_kind' => ScheduleKind::RoomRental->value,
            'date' => '2026-06-18',
            'starts_at' => '2026-06-18T16:00',
            'class_type_id' => $classType->id,
            'room_id' => $room->id,
        ];

        $this->actingAs($firstCustomer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertRedirect(route('customer.dashboard', $account->slug));

        $scheduledClass = ScheduledClass::whereBelongsTo($account)->where('title', 'Rental 60')->firstOrFail();

        $this->actingAs($secondCustomer, 'customer')
            ->post(route('public.booking.store', [$account->slug, $location->slug]), $payload)
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(1, ClassBooking::whereBelongsTo($scheduledClass)->count());

        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $accountOverrides
     * @param  array<string, mixed>  $scheduledClassOverrides
     * @return array{0: Account, 1: Location, 2: ScheduledClass, 3: ClassType}
     */
    private function publicGroupClass(array $accountOverrides = [], array $scheduledClassOverrides = []): array
    {
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'default_language' => 'en',
            ...$accountOverrides,
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create();
        $classType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'booking_cutoff_minutes' => null,
        ]);
        $scheduledClass = ScheduledClass::factory()
            ->for($account)
            ->for($location)
            ->for($room)
            ->for($classType)
            ->create([
                'title' => 'Public Pole',
                'starts_at' => Carbon::parse('2026-06-18 10:00:00', 'UTC'),
                'ends_at' => Carbon::parse('2026-06-18 11:00:00', 'UTC'),
                ...$scheduledClassOverrides,
            ]);

        return [$account, $location, $scheduledClass, $classType];
    }

    /**
     * @return array{0: Account, 1: Location, 2: Room}
     */
    private function manualBookingSetup(string $slug): array
    {
        $account = Account::factory()->create([
            'slug' => $slug,
            'timezone' => 'UTC',
            'default_language' => 'en',
        ]);
        $location = Location::factory()->for($account)->create(['slug' => 'main', 'timezone' => 'UTC']);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 1]);

        return [$account, $location, $room];
    }

    /**
     * @param  array<int, string>  $times
     */
    private function createPublicTrainerTimeframes(Account $account, Trainer $trainer, Location $location, array $times): void
    {
        foreach ($times as $time) {
            $startsAt = Carbon::parse('2026-06-18 '.$time.':00', 'UTC');

            TrainerPrivateTimeframe::factory()->create([
                'account_id' => $account->id,
                'trainer_id' => $trainer->id,
                'location_id' => $location->id,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addMinutes(30),
            ]);
        }
    }
}
