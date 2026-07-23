<?php

namespace Tests\Feature;

use App\Actions\CancelScheduledClassForStudio;
use App\Actions\CreateManualScheduledClass;
use App\Actions\GenerateScheduleOccurrences;
use App\Actions\RestoreScheduledClassCancellation;
use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\PublicScheduleView;
use App\Enums\ScheduleKind;
use App\Models\Account;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\PeopleCounterSample;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassPeopleCount;
use App\Models\ScheduleSeries;
use App\Models\Trainer;
use App\Models\User;
use App\Support\ManualQuickBookingAvailability;
use App\Support\Mobile\MobileScheduledClassPayload;
use App\Support\Mobile\MobileSessionIssuer;
use App\Support\PeopleCounter\PeopleCounterSummarizer;
use App\Support\Reports\TrainerReportData;
use App\Support\ScheduleKindRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InternalClassTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_internal_class_is_opt_in_and_settings_accept_its_color_without_exposing_pass_tabs(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'enabled_schedule_kinds' => null,
            'schedule_kind_colors' => null,
        ]);
        $account->addOwner($owner);

        $this->assertSame([
            ScheduleKind::GroupClass->value,
            ScheduleKind::PrivateLesson->value,
            ScheduleKind::RoomRental->value,
        ], $account->enabledScheduleKindValues());
        $this->assertFalse($account->hasScheduleKindEnabled(ScheduleKind::InternalClass));
        $this->assertSame('#F59E0B', $account->scheduleKindColor(ScheduleKind::InternalClass));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), $this->accountSettingsPayload($account, [
                ...ScheduleKindRegistry::defaultEnabledValues(),
                ScheduleKind::InternalClass->value,
            ]))
            ->assertRedirect();

        $account->refresh();

        $this->assertTrue($account->hasScheduleKindEnabled(ScheduleKind::InternalClass));
        $this->assertSame('#D97706', $account->scheduleKindColor(ScheduleKind::InternalClass));

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.internal-classes.index', $account))
            ->assertOk()
            ->assertSee(__('app.internal_classes'));

        $passPlansResponse = $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertOk();

        $this->assertArrayNotHasKey('internal_class', $passPlansResponse->viewData('scheduleKindTabs'));

        $this->actingAs($owner)
            ->put(route('dashboard.accounts.update', $account), $this->accountSettingsPayload(
                $account,
                ScheduleKindRegistry::defaultEnabledValues(),
            ))
            ->assertRedirect();

        $this->assertFalse($account->fresh()->hasScheduleKindEnabled(ScheduleKind::InternalClass));
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.internal-classes.index', $account))
            ->assertNotFound();

        $account->update([
            'enabled_schedule_kinds' => [ScheduleKind::InternalClass->value],
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.index', $account))
            ->assertOk()
            ->assertSee(__('app.no_class_pass_eligible_formats'))
            ->assertDontSee(route('dashboard.accounts.class-pass-plans.create', $account), false);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-plans.create', $account))
            ->assertNotFound();

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-segments.index', $account))
            ->assertOk()
            ->assertSee(__('app.no_class_pass_eligible_formats'))
            ->assertDontSee(route('dashboard.accounts.class-pass-segments.create', $account), false);
        $this->actingAs($owner)
            ->get(route('dashboard.accounts.class-pass-segments.create', $account))
            ->assertNotFound();
    }

    public function test_internal_class_type_catalog_hides_and_canonicalizes_customer_booking_fields(): void
    {
        $context = $this->context();

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.internal-classes.create', $context['account']))
            ->assertOk()
            ->assertDontSee('name="booking_cutoff_minutes"', false)
            ->assertDontSee('name="cancellation_cutoff_minutes"', false)
            ->assertDontSee('name="default_capacity"', false);

        $this->actingAs($context['owner'])
            ->post(route('dashboard.accounts.internal-classes.store', $context['account']), [
                'name' => 'Staff practice',
                'slug' => 'staff-practice',
                'default_duration_minutes' => 75,
                'default_capacity' => 99,
                'booking_cutoff_minutes' => 120,
                'cancellation_cutoff_minutes' => 240,
                'is_active' => 1,
            ])
            ->assertRedirect(route('dashboard.accounts.internal-classes.index', $context['account']));

        $classType = $context['account']->classTypes()
            ->where('slug', 'staff-practice')
            ->firstOrFail();

        $this->assertSame(ScheduleKind::InternalClass, $classType->schedule_kind);
        $this->assertNull($classType->default_capacity);
        $this->assertNull($classType->booking_cutoff_minutes);
        $this->assertNull($classType->cancellation_cutoff_minutes);
    }

    public function test_owner_and_trainer_can_create_internal_classes_with_required_active_tenant_resources(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $context = $this->context();
        $additionalTrainer = Trainer::factory()->for($context['account'])->create([
            'name' => 'Additional trainer',
        ]);

        $response = $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'additional_trainer_ids' => [$additionalTrainer->id],
                'title' => 'Owner practice',
                'starts_at' => '2026-07-20T12:00',
            ]))
            ->assertCreated()
            ->assertJsonPath('reload', true);

        $scheduledClass = ScheduledClass::findOrFail($response->json('scheduled_class_id'));
        $this->assertSame($context['trainer']->id, $scheduledClass->trainer_id);
        $this->assertEquals([$additionalTrainer->id], $scheduledClass->additionalTrainerIds()->all());
        $this->assertFalse($scheduledClass->is_public);
        $this->assertNull($scheduledClass->capacity);
        $this->assertNull($scheduledClass->booking_cutoff_minutes);
        $this->assertNull($scheduledClass->cancellation_cutoff_minutes);
        $this->assertFalse($scheduledClass->acceptsCustomerBookings());
        $this->assertSame(2, $scheduledClass->peopleCounterTrainerAdjustment());

        $trainerUser = User::factory()->create();
        $context['account']->users()->syncWithoutDetaching([
            $trainerUser->id => ['role' => AccountRole::Trainer->value],
        ]);
        $loggedInTrainer = Trainer::factory()->for($context['account'])->create([
            'user_id' => $trainerUser->id,
            'name' => 'Logged-in trainer',
        ]);

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.scheduled-classes.index', $context['account']))
            ->assertOk()
            ->assertSee('data-manual-class-open="internal_class"', false)
            ->assertSee('data-trainer-multi-select', false)
            ->assertSee('name="additional_trainer_ids[]"', false)
            ->assertSee('value="'.$loggedInTrainer->id.'" selected', false);

        $this->actingAs($trainerUser)
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'trainer_id' => $loggedInTrainer->id,
                'title' => 'Trainer practice',
                'starts_at' => '2026-07-20T14:00',
            ]))
            ->assertCreated();

        $otherAccount = Account::factory()->create();
        $otherTrainer = Trainer::factory()->for($otherAccount)->create();
        $inactiveTrainer = Trainer::factory()->for($context['account'])->create([
            'is_active' => false,
        ]);

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'trainer_id' => $otherTrainer->id,
                'starts_at' => '2026-07-20T16:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('trainer_id');

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'additional_trainer_ids' => [$context['trainer']->id],
                'starts_at' => '2026-07-20T16:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('additional_trainer_ids');

        foreach ([$otherTrainer, $inactiveTrainer] as $invalidAdditionalTrainer) {
            $this->actingAs($context['owner'])
                ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                    'additional_trainer_ids' => [$invalidAdditionalTrainer->id],
                    'starts_at' => '2026-07-20T16:00',
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('additional_trainer_ids.0');
        }

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'trainer_id' => null,
                'title' => null,
                'duration_minutes' => null,
                'starts_at' => '2026-07-20T16:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['trainer_id', 'title', 'duration_minutes']);

        $context['internalType']->update(['is_active' => false]);

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'starts_at' => '2026-07-20T16:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('class_type_id');

        $context['internalType']->update(['is_active' => true]);
        $context['account']->update([
            'enabled_schedule_kinds' => ScheduleKindRegistry::defaultEnabledValues(),
        ]);

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'starts_at' => '2026-07-20T16:00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('class_type_id');

        $context['account']->update([
            'enabled_schedule_kinds' => ScheduleKindRegistry::allValues(),
        ]);
        $receptionist = User::factory()->create();
        $context['account']->users()->syncWithoutDetaching([
            $receptionist->id => ['role' => AccountRole::Receptionist->value],
        ]);

        $this->actingAs($receptionist)
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'starts_at' => '2026-07-20T16:00',
            ]))
            ->assertForbidden();
    }

    public function test_internal_class_rejects_room_and_trainer_overlaps_and_supports_full_future_editing(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $context = $this->context();
        $otherRoom = Room::factory()->for($context['account'])->for($context['location'])->create();
        $otherTrainer = Trainer::factory()->for($context['account'])->create(['name' => 'Second trainer']);
        $additionalTrainer = Trainer::factory()->for($context['account'])->create(['name' => 'Third trainer']);

        $this->scheduledClass($context, $context['groupType'], '2026-07-20 12:00:00', '2026-07-20 13:00:00');

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'trainer_id' => $otherTrainer->id,
                'starts_at' => '2026-07-20T12:30',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'room_id' => $otherRoom->id,
                'starts_at' => '2026-07-20T12:30',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->actingAs($context['owner'])
            ->postJson($this->storeUrl($context['account']), $this->internalClassPayload($context, [
                'room_id' => $otherRoom->id,
                'trainer_id' => $otherTrainer->id,
                'additional_trainer_ids' => [$context['trainer']->id],
                'starts_at' => '2026-07-20T12:30',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $scheduledClass = app(CreateManualScheduledClass::class)->execute(
            $context['account'],
            ScheduleKind::InternalClass,
            $this->internalClassPayload($context, ['starts_at' => '2026-07-20T14:00']),
        );

        $this->actingAs($context['owner'])
            ->patchJson(
                route('dashboard.accounts.scheduled-classes.internal.update', [$context['account'], $scheduledClass]),
                $this->internalClassPayload($context, [
                    'room_id' => $otherRoom->id,
                    'trainer_id' => $otherTrainer->id,
                    'additional_trainer_ids' => [$additionalTrainer->id],
                    'class_type_id' => $context['internalType']->id,
                    'title' => 'Updated closed practice',
                    'description' => 'Updated notes',
                    'starts_at' => '2026-07-20T15:00',
                    'duration_minutes' => 90,
                ]),
            )
            ->assertOk()
            ->assertJsonPath('reload', true);

        $scheduledClass->refresh();
        $this->assertSame($otherRoom->id, $scheduledClass->room_id);
        $this->assertSame($otherTrainer->id, $scheduledClass->trainer_id);
        $this->assertEquals([$additionalTrainer->id], $scheduledClass->additionalTrainerIds()->all());
        $this->assertSame('Updated closed practice', $scheduledClass->title);
        $this->assertSame(90, $scheduledClass->durationMinutes());
        $this->assertTrue($scheduledClass->is_manually_modified);
        $this->assertDatabaseHas('scheduled_class_trainer_changes', [
            'scheduled_class_id' => $scheduledClass->id,
            'previous_trainer_id' => $context['trainer']->id,
            'new_trainer_id' => $otherTrainer->id,
            'actor_user_id' => $context['owner']->id,
        ]);

        $this->actingAs($context['owner'])
            ->patchJson(
                route('dashboard.accounts.scheduled-classes.internal.update', [$context['account'], $scheduledClass]),
                $this->internalClassPayload($context, [
                    'room_id' => $otherRoom->id,
                    'trainer_id' => $otherTrainer->id,
                    'starts_at' => '2026-07-20T17:00',
                ]),
            )
            ->assertOk();

        $this->assertSame([], $scheduledClass->fresh()->additionalTrainerIds()->all());

        $this->actingAs($context['owner'])
            ->patchJson(
                route('dashboard.accounts.scheduled-classes.internal.update', [$context['account'], $scheduledClass]),
                $this->internalClassPayload($context, [
                    'room_id' => $otherRoom->id,
                    'trainer_id' => $otherTrainer->id,
                    'starts_at' => '2026-07-20T08:00',
                ]),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $scheduledClass->forceFill([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ])->save();

        $this->actingAs($context['owner'])
            ->patchJson(
                route('dashboard.accounts.scheduled-classes.internal.update', [$context['account'], $scheduledClass]),
                $this->internalClassPayload($context, [
                    'room_id' => $otherRoom->id,
                    'trainer_id' => $otherTrainer->id,
                    'starts_at' => '2026-07-20T16:00',
                ]),
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('_form');
    }

    public function test_internal_class_blocks_manual_availability_and_cancel_restore_obeys_occupancy(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $context = $this->context();
        $otherRoom = Room::factory()->for($context['account'])->for($context['location'])->create();
        $otherTrainer = Trainer::factory()->for($context['account'])->create();
        $internalClass = app(CreateManualScheduledClass::class)->execute(
            $context['account'],
            ScheduleKind::InternalClass,
            $this->internalClassPayload($context, [
                'trainer_id' => $otherTrainer->id,
                'additional_trainer_ids' => [$context['trainer']->id],
                'starts_at' => '2026-07-20T12:00',
            ]),
        );
        $availability = app(ManualQuickBookingAvailability::class);
        $availabilityInput = [
            'location_id' => $context['location']->id,
            'room_id' => $otherRoom->id,
            'class_type_id' => $context['privateType']->id,
            'trainer_id' => $context['trainer']->id,
        ];

        $this->assertFalse($availability->hasStart(
            $context['account'],
            ScheduleKind::PrivateLesson,
            '2026-07-20T12:00',
            $availabilityInput,
        ));

        app(CancelScheduledClassForStudio::class)->execute(
            $context['account'],
            $internalClass,
            $context['owner'],
        );

        $this->assertTrue($availability->hasStart(
            $context['account'],
            ScheduleKind::PrivateLesson,
            '2026-07-20T12:00',
            $availabilityInput,
        ));

        app(RestoreScheduledClassCancellation::class)->execute(
            $context['account'],
            $internalClass,
            $context['owner'],
        );

        $this->assertFalse($availability->hasStart(
            $context['account'],
            ScheduleKind::PrivateLesson,
            '2026-07-20T12:00',
            $availabilityInput,
        ));

        app(CancelScheduledClassForStudio::class)->execute(
            $context['account'],
            $internalClass->fresh(),
            $context['owner'],
        );

        $conflictingClass = $this->scheduledClass(
            $context,
            $context['groupType'],
            '2026-07-20 12:00:00',
            '2026-07-20 13:00:00',
        );
        $conflictingClass->update(['room_id' => $otherRoom->id]);

        $this->expectException(ValidationException::class);
        app(RestoreScheduledClassCancellation::class)->execute(
            $context['account'],
            $internalClass->fresh(),
            $context['owner'],
        );
    }

    public function test_recurring_generation_skips_internal_conflict_and_retries_after_cancellation(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $context = $this->context([
            'schedule_generation_weeks' => 1,
        ]);
        $otherRoom = Room::factory()->for($context['account'])->for($context['location'])->create();
        $otherTrainer = Trainer::factory()->for($context['account'])->create();
        $internalClass = app(CreateManualScheduledClass::class)->execute(
            $context['account'],
            ScheduleKind::InternalClass,
            $this->internalClassPayload($context, [
                'room_id' => $otherRoom->id,
                'trainer_id' => $otherTrainer->id,
                'additional_trainer_ids' => [$context['trainer']->id],
                'starts_at' => '2026-07-20T14:00',
            ]),
        );
        $series = ScheduleSeries::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['groupType'])
            ->for($context['trainer'])
            ->create([
                'weekday' => 1,
                'start_time' => '14:00',
                'start_date' => '2026-07-20',
            ]);

        app(GenerateScheduleOccurrences::class)->execute($series);

        $this->assertDatabaseMissing('scheduled_classes', [
            'schedule_series_id' => $series->id,
            'starts_at' => '2026-07-20 14:00:00',
        ]);
        $this->assertDatabaseHas('scheduled_classes', [
            'schedule_series_id' => $series->id,
            'starts_at' => '2026-07-27 14:00:00',
        ]);

        app(CancelScheduledClassForStudio::class)->execute(
            $context['account'],
            $internalClass,
            $context['owner'],
        );
        app(GenerateScheduleOccurrences::class)->execute($series->fresh());

        $this->assertDatabaseHas('scheduled_classes', [
            'schedule_series_id' => $series->id,
            'starts_at' => '2026-07-20 14:00:00',
        ]);
    }

    public function test_internal_class_is_hidden_from_public_and_customer_mobile_but_visible_to_staff_mobile(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $context = $this->context([
            'slug' => 'internal-mobile-studio',
            'public_schedule_view' => PublicScheduleView::Classic->value(),
        ]);
        $context['location']->update(['slug' => 'main']);
        $trainerUser = User::factory()->create();
        $context['account']->users()->syncWithoutDetaching([
            $trainerUser->id => ['role' => AccountRole::Trainer->value],
        ]);
        $additionalTrainer = Trainer::factory()->for($context['account'])->create([
            'user_id' => $trainerUser->id,
            'name' => 'Mobile additional trainer',
        ]);
        $internalClass = app(CreateManualScheduledClass::class)->execute(
            $context['account'],
            ScheduleKind::InternalClass,
            $this->internalClassPayload($context, [
                'additional_trainer_ids' => [$additionalTrainer->id],
                'title' => 'Secret staff practice',
                'starts_at' => '2026-07-21T12:00',
            ]),
        );
        $ownerToken = (string) app(MobileSessionIssuer::class)
            ->issueForStaff($context['account'], $context['owner'], AccountRole::Owner->value)
            ->getAttribute('plain_token');
        $trainerToken = (string) app(MobileSessionIssuer::class)
            ->issueForStaff($context['account'], $trainerUser, AccountRole::Trainer->value)
            ->getAttribute('plain_token');
        $customer = Customer::factory()->for($context['account'])->create();
        $customerToken = (string) app(MobileSessionIssuer::class)
            ->issueForCustomer($context['account'], $customer)
            ->getAttribute('plain_token');

        $this->get('/internal-mobile-studio/main/schedule')
            ->assertOk()
            ->assertDontSee('Secret staff practice');

        $context['account']->update([
            'public_schedule_view' => PublicScheduleView::CompactBooking->value(),
        ]);
        $this->get('/internal-mobile-studio/main/schedule')
            ->assertOk()
            ->assertDontSee('Secret staff practice');

        $this->getJson('/api/v1/public/internal-mobile-studio/main/schedule')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Secret staff practice']);

        $this->withToken($ownerToken)
            ->getJson('/api/v1/mobile/schedule?from=2026-07-21&to=2026-07-22')
            ->assertOk()
            ->assertJsonPath('data.0.id', $internalClass->id)
            ->assertJsonPath('data.0.schedule_kind', ScheduleKind::InternalClass->value)
            ->assertJsonPath('data.0.booking_open', false)
            ->assertJsonPath('data.0.booked_count', 0)
            ->assertJsonPath('data.0.available_spots', null)
            ->assertJsonPath('data.0.additional_trainers.0.id', $additionalTrainer->id)
            ->assertJsonPath('data.0.additional_trainers.0.name', 'Mobile additional trainer')
            ->assertJsonPath('data.0.bookings', []);

        $this->withToken($trainerToken)
            ->getJson('/api/v1/mobile/schedule?from=2026-07-21&to=2026-07-22')
            ->assertOk()
            ->assertJsonPath('data.0.id', $internalClass->id);

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.scheduled-classes.index', [
                $context['account'],
                'tab' => 'tomorrow',
            ]))
            ->assertOk()
            ->assertSee('Secret staff practice');

        $this->withToken($customerToken)
            ->getJson('/api/v1/mobile/schedule?from=2026-07-21&to=2026-07-22')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_booking_entry_points_and_reports_ignore_internal_classes_even_with_malformed_booking(): void
    {
        Carbon::setTestNow('2026-07-20 15:00:00');
        $context = $this->context();
        $internalClass = $this->scheduledClass(
            $context,
            $context['internalType'],
            '2026-07-20 12:00:00',
            '2026-07-20 13:00:00',
        );
        $additionalTrainer = Trainer::factory()->for($context['account'])->create([
            'name' => 'Unpaid additional trainer',
        ]);
        $internalClass->additionalTrainers()->syncWithPivotValues(
            [$additionalTrainer->id],
            ['account_id' => $context['account']->id],
        );
        $customer = Customer::factory()->for($context['account'])->create();

        $this->actingAs($context['owner'])
            ->postJson(
                route('dashboard.accounts.scheduled-classes.bookings.store', [$context['account'], $internalClass]),
                ['customer_id' => $customer->id],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_id');

        $this->actingAs($context['owner'])
            ->postJson(
                route('dashboard.accounts.scheduled-classes.corrections.bookings.store', [$context['account'], $internalClass]),
                [
                    'customer_id' => $customer->id,
                    'status' => ClassBookingStatus::Attended->value,
                    'reason' => 'Attempted correction',
                ],
            )
            ->assertUnprocessable();

        $staffToken = (string) app(MobileSessionIssuer::class)
            ->issueForStaff($context['account'], $context['owner'], AccountRole::Owner->value)
            ->getAttribute('plain_token');
        $this->withToken($staffToken)
            ->postJson('/api/v1/mobile/classes/'.$internalClass->id.'/staff-bookings', [
                'customer_id' => $customer->id,
                'notes' => null,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_class_id');

        ClassBooking::factory()
            ->for($context['account'])
            ->for($internalClass, 'scheduledClass')
            ->for($customer)
            ->create(['status' => ClassBookingStatus::Attended->value]);
        $context['account']->update([
            'allow_rtsp_cameras' => true,
            'enable_people_counter' => true,
        ]);
        $context['room']->update([
            'rtsp_enabled' => true,
            'rtsp_url' => 'rtsp://camera.example.test/live',
        ]);
        PeopleCounterSample::factory()->for($internalClass)->create([
            'account_id' => $context['account']->id,
            'location_id' => $context['location']->id,
            'room_id' => $context['room']->id,
            'captured_at' => Carbon::parse('2026-07-20 12:30:00'),
            'status' => PeopleCounterSample::StatusSucceeded,
            'detected_count' => 2,
        ]);
        $peopleCounterDebug = null;

        $summary = app(PeopleCounterSummarizer::class)->summarizeClass(
            $internalClass,
            function (string $event, array $context) use (&$peopleCounterDebug): void {
                if ($event === 'summarize.class.finished') {
                    $peopleCounterDebug = $context;
                }
            },
        );

        $this->assertSame(2, $peopleCounterDebug['expected_people_count'] ?? null);
        $this->assertSame(2, $peopleCounterDebug['trainer_adjustment'] ?? null);
        $this->assertSame(0, $summary->attended_count);
        $this->assertSame(0, $summary->delta);
        $this->assertSame(ScheduledClassPeopleCount::StatusMatched, $summary->status);

        $this->actingAs($context['owner'])
            ->get(route('dashboard.accounts.reports.people-counter', $context['account']))
            ->assertOk()
            ->assertSee(
                'data-class-counts="'.$internalClass->id.':0:0:0:'.ScheduledClassPeopleCount::StatusMatched.'"',
                false,
            );

        $internalClass->load(['classType.activityDirection', 'classBookings.customer']);
        $mobilePayload = app(MobileScheduledClassPayload::class)->forClass(
            $internalClass,
            includeBookings: true,
        );
        $this->assertSame(0, $mobilePayload['booked_count']);
        $this->assertNull($mobilePayload['available_spots']);
        $this->assertFalse($mobilePayload['booking_open']);
        $this->assertSame([], $mobilePayload['bookings']);

        $reportRows = app(TrainerReportData::class)->forAccount($context['account'], [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'location_id' => null,
            'booking_statuses' => [
                ClassBookingStatus::Booked->value,
                ClassBookingStatus::Attended->value,
            ],
        ]);
        $trainerRow = $reportRows->firstWhere('trainer.id', $context['trainer']->id);

        $this->assertNotNull($trainerRow);
        $this->assertSame(0, $trainerRow['classes_count']);
        $this->assertSame(0, $trainerRow['group_people_count']);
        $additionalTrainerRow = $reportRows->firstWhere('trainer.id', $additionalTrainer->id);
        $this->assertNotNull($additionalTrainerRow);
        $this->assertSame(0, $additionalTrainerRow['classes_count']);
        $this->assertSame(0, $additionalTrainerRow['group_people_count']);
        $this->assertTrue($internalClass->peopleCounterTrackable()->whereKey($internalClass->id)->exists());
        $this->assertSame(2, $internalClass->peopleCounterTrainerAdjustment());
    }

    /**
     * @param  array<string, mixed>  $accountAttributes
     * @return array<string, mixed>
     */
    private function context(array $accountAttributes = []): array
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create([
            'timezone' => 'UTC',
            'default_language' => 'en',
            'enabled_schedule_kinds' => ScheduleKindRegistry::allValues(),
            ...$accountAttributes,
        ]);
        $account->addOwner($owner);
        $location = Location::factory()->for($account)->create([
            'timezone' => 'UTC',
            'slug' => 'main',
        ]);
        $room = Room::factory()->for($account)->for($location)->create(['capacity' => 12]);
        $trainer = Trainer::factory()->for($account)->create(['name' => 'Internal trainer']);
        $internalType = ClassType::factory()->for($account)->create([
            'name' => 'Staff training',
            'schedule_kind' => ScheduleKind::InternalClass->value,
            'default_duration_minutes' => 60,
            'default_capacity' => null,
        ]);
        $groupType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::GroupClass->value,
            'default_duration_minutes' => 60,
        ]);
        $privateType = ClassType::factory()->for($account)->create([
            'schedule_kind' => ScheduleKind::PrivateLesson->value,
            'default_duration_minutes' => 60,
        ]);

        return compact(
            'owner',
            'account',
            'location',
            'room',
            'trainer',
            'internalType',
            'groupType',
            'privateType',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function internalClassPayload(array $context, array $overrides = []): array
    {
        return [
            'location_id' => $context['location']->id,
            'room_id' => $context['room']->id,
            'class_type_id' => $context['internalType']->id,
            'trainer_id' => $context['trainer']->id,
            'starts_at' => '2026-07-20T12:00',
            'duration_minutes' => 60,
            'title' => 'Closed practice',
            'description' => 'Staff-only activity',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function scheduledClass(
        array $context,
        ClassType $classType,
        string $startsAt,
        string $endsAt,
    ): ScheduledClass {
        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($classType)
            ->for($context['trainer'])
            ->create([
                'title' => $classType->name,
                'starts_at' => Carbon::parse($startsAt, 'UTC'),
                'ends_at' => Carbon::parse($endsAt, 'UTC'),
                'capacity' => $classType->schedule_kind === ScheduleKind::InternalClass ? null : 12,
                'is_public' => $classType->schedule_kind === ScheduleKind::GroupClass,
            ]);
    }

    /**
     * @param  array<int, string>  $enabledScheduleKinds
     * @return array<string, mixed>
     */
    private function accountSettingsPayload(Account $account, array $enabledScheduleKinds): array
    {
        return [
            'brand_tab' => 'formats',
            'name' => $account->name,
            'slug' => $account->slug,
            'default_language' => 'en',
            'country_code' => 'UA',
            'default_currency' => 'UAH',
            'brand_color' => '#3B223F',
            'timezone' => 'UTC',
            'enabled_schedule_kinds_present' => '1',
            'enabled_schedule_kinds' => $enabledScheduleKinds,
            'schedule_kind_colors_present' => '1',
            'schedule_kind_colors' => [
                ScheduleKind::GroupClass->value => '#A3E635',
                ScheduleKind::PrivateLesson->value => '#A78AB9',
                ScheduleKind::RoomRental->value => '#38BDF8',
                ScheduleKind::InternalClass->value => '#D97706',
            ],
        ];
    }

    private function storeUrl(Account $account): string
    {
        return route('dashboard.accounts.scheduled-classes.manual.store', [
            $account,
            ScheduleKind::InternalClass->value,
        ]);
    }
}
