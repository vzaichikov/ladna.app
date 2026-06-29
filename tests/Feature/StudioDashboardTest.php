<?php

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Enums\ClassBookingStatus;
use App\Enums\CustomerClassPassReservationStatus;
use App\Enums\CustomerClassPassStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\StudioPermission;
use App\Enums\WebsiteLeadStatus;
use App\Models\Account;
use App\Models\AccountMembership;
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
use App\Models\User;
use App\Models\WebsiteLead;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StudioDashboardTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_owner_sees_operational_studio_dashboard_without_setup_shortcuts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $otherAccount = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);

        $context = $this->classContext($account, trainerName: 'Настя');
        $liveClass = $this->scheduledClass($context, 'Live Stretch', '2026-06-24 10:00:00', '2026-06-24 11:00:00', 4);
        $nextClass = $this->scheduledClass($context, 'Next Pole', '2026-06-24 12:00:00', '2026-06-24 13:00:00', 6);
        $this->scheduledClass($context, 'Cancelled Today', '2026-06-24 14:00:00', '2026-06-24 15:00:00', 10, ScheduledClassStatus::Cancelled);
        $this->scheduledClass($this->classContext($otherAccount), 'Other Studio Class', '2026-06-24 10:00:00', '2026-06-24 11:00:00', 40);

        $this->booking($account, $liveClass, ClassBookingStatus::Booked, 'Олена');
        $this->booking($account, $liveClass, ClassBookingStatus::Attended, 'Марія');
        $this->booking($account, $liveClass, ClassBookingStatus::NoShow, 'Ірина');
        $this->booking($account, $nextClass, ClassBookingStatus::Cancelled, 'Софія');

        $this->activePass($account, 'PASS-0001');
        $this->activePass($account, 'PASS-0002');
        $this->inactivePass($account, 'PASS-0003');
        CustomerClassPass::factory()->for($otherAccount)->create(['code' => 'PASS-0004']);

        Customer::factory()->for($account)->create(['name' => 'Новий клієнт', 'created_at' => '2026-06-22 12:00:00']);
        Customer::factory()->for($otherAccount)->create(['name' => 'Клієнт іншої студії']);

        WebsiteLead::factory()->for($account)->create([
            'name' => 'Нова заявка',
            'status' => WebsiteLeadStatus::New->value,
            'created_at' => '2026-06-24 09:00:00',
        ]);
        WebsiteLead::factory()->for($account)->create([
            'name' => 'Передзвонити',
            'status' => WebsiteLeadStatus::Callback->value,
            'created_at' => '2026-06-23 09:00:00',
        ]);
        WebsiteLead::factory()->for($account)->create(['status' => WebsiteLeadStatus::Rejected->value]);
        WebsiteLead::factory()->for($otherAccount)->create(['name' => 'Чужа заявка']);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('Студія сьогодні')
            ->assertSee('Активні абонементи')
            ->assertSee('Відкриті заявки з сайту')
            ->assertSee('Завантаження сьогодні')
            ->assertSee('20%')
            ->assertSee('2 / 10')
            ->assertSee('Live Stretch')
            ->assertSee('Next Pole')
            ->assertSee('2 / 4')
            ->assertSee('0 / 6')
            ->assertDontSee('Cancelled Today')
            ->assertDontSee('Other Studio Class')
            ->assertDontSee('Швидкі дії')
            ->assertDontSee(route('dashboard.accounts.locations.create', $account), false)
            ->assertDontSee('Колір бренду');
    }

    public function test_owner_dashboard_groups_problem_moments_with_links(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $context = $this->classContext($account, trainerName: 'Problem Trainer');
        $scheduledClass = $this->scheduledClass($context, 'Unreserved Pole', '2026-06-25 10:00:00', '2026-06-25 11:00:00', 8);

        $this->booking($account, $scheduledClass, ClassBookingStatus::Booked, 'Без резерву');
        $this->activePass($account, 'UNPAID-001');
        CustomerClassPass::factory()
            ->for($account)
            ->for(Customer::factory()->for($account))
            ->for(ClassPassPlan::factory()->for($account), 'classPassPlan')
            ->create([
                'code' => 'FROZEN-001',
                'is_paid' => true,
                'status' => CustomerClassPassStatus::Freezed->value,
                'is_active' => true,
                'frozen_at' => Carbon::parse('2026-06-24 09:00:00'),
            ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee(__('app.studio_problem_moments'))
            ->assertSee(__('app.problem_unpaid_class_passes'))
            ->assertSee(__('app.problem_unreserved_bookings'))
            ->assertSee(__('app.problem_freezed_class_passes'))
            ->assertSee(route('dashboard.accounts.customer-class-passes.index', [
                'account' => $account,
                'state' => 'active',
                'payment_status' => 'unpaid',
            ]))
            ->assertSee(route('dashboard.accounts.trainers.index', $account), false)
            ->assertSee(route('dashboard.accounts.customer-class-passes.index', [
                'account' => $account,
                'state' => 'freezed',
            ]), false);

        Carbon::setTestNow();
    }

    public function test_trainer_list_shows_unreserved_booking_issue_badge_and_modal_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 10:30:00', 'UTC'));

        $owner = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        $account->addOwner($owner);
        $context = $this->classContext($account, trainerName: 'Issue Trainer');
        $unreservedClass = $this->scheduledClass($context, 'Unreserved Class', '2026-06-25 10:00:00', '2026-06-25 11:00:00', 8);
        $reservedClass = $this->scheduledClass($context, 'Reserved Class', '2026-06-26 10:00:00', '2026-06-26 11:00:00', 8);
        $usedClass = $this->scheduledClass($context, 'Used Class', '2026-06-23 10:00:00', '2026-06-23 11:00:00', 8);
        $unreservedBooking = $this->booking($account, $unreservedClass, ClassBookingStatus::Booked, 'Needs Reserve');
        $reservedBooking = $this->booking($account, $reservedClass, ClassBookingStatus::Booked, 'Reserved Customer');
        $usedBooking = $this->booking($account, $usedClass, ClassBookingStatus::Attended, 'Used Customer');
        $reservedPass = $this->activePass($account, 'RESERVED-001');
        $usedPass = $this->activePass($account, 'USED-001');

        $reservedPass->reservations()->create([
            'account_id' => $account->id,
            'class_booking_id' => $reservedBooking->id,
            'scheduled_class_id' => $reservedClass->id,
            'status' => CustomerClassPassReservationStatus::Reserved->value,
            'reserved_at' => Carbon::parse('2026-06-24 09:00:00'),
        ]);
        $usedPass->reservations()->create([
            'account_id' => $account->id,
            'class_booking_id' => $usedBooking->id,
            'scheduled_class_id' => $usedClass->id,
            'status' => CustomerClassPassReservationStatus::Used->value,
            'reserved_at' => Carbon::parse('2026-06-22 09:00:00'),
            'used_at' => Carbon::parse('2026-06-23 10:00:00'),
        ]);

        $this->actingAs($owner)
            ->get(route('dashboard.accounts.trainers.index', $account))
            ->assertOk()
            ->assertSee('Issue Trainer')
            ->assertSee(__('app.trainer_unreserved_bookings', ['count' => 1]))
            ->assertSee('data-trainer-issues-open="'.$context['trainer']->id.'"', false)
            ->assertSee(__('app.unreserved_booking_issues_for_trainer', ['trainer' => 'Issue Trainer']))
            ->assertSee('Unreserved Class')
            ->assertSee('Needs Reserve')
            ->assertSee(route('dashboard.accounts.customers.edit', [$account, $unreservedBooking->customer]), false)
            ->assertDontSee('Reserved Customer')
            ->assertDontSee('Used Customer');

        Carbon::setTestNow();
    }

    public function test_trainer_sees_only_assigned_agenda_with_attendance_controls(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 08:00:00', 'UTC'));

        $trainerUser = User::factory()->create(['name' => 'Trainer User']);
        $account = Account::factory()->create(['timezone' => 'UTC']);
        AccountMembership::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->create(['role' => AccountRole::Trainer->value, 'permissions' => null]);

        $context = $this->classContext($account, trainerName: 'Олена Тренер', trainerUser: $trainerUser);
        $otherContext = $this->classContext($account, trainerName: 'Інший тренер');
        $todayClass = $this->scheduledClass($context, 'Trainer Today', '2026-06-24 09:00:00', '2026-06-24 10:00:00', 8);
        $this->scheduledClass($context, 'Trainer Tomorrow', '2026-06-25 12:00:00', '2026-06-25 13:00:00', 8);
        $this->scheduledClass($context, 'Trainer Friday', '2026-06-26 12:00:00', '2026-06-26 13:00:00', 8);
        $this->scheduledClass($otherContext, 'Other Trainer Today', '2026-06-24 09:00:00', '2026-06-24 10:00:00', 8);
        $booking = $this->booking($account, $todayClass, ClassBookingStatus::Booked, 'Клієнтка тренера');

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('Мої заняття')
            ->assertSee('Розклад Олена Тренер')
            ->assertSee('Trainer Today')
            ->assertSee('Trainer Tomorrow')
            ->assertSee('Trainer Friday')
            ->assertSee('Клієнтка тренера')
            ->assertSee(route('dashboard.accounts.bookings.update', [$account, $booking]), false)
            ->assertDontSee('Other Trainer Today')
            ->assertDontSee('Активні абонементи')
            ->assertDontSee('Відкриті заявки з сайту')
            ->assertDontSee('Швидкі дії');
    }

    public function test_trainer_without_attendance_permission_sees_agenda_without_attendance_controls(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 08:00:00', 'UTC'));

        $trainerUser = User::factory()->create();
        $account = Account::factory()->create(['timezone' => 'UTC']);
        AccountMembership::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->create([
                'role' => AccountRole::Trainer->value,
                'permissions' => [StudioPermission::ManageSchedule->value],
            ]);

        $context = $this->classContext($account, trainerName: 'Олена Тренер', trainerUser: $trainerUser);
        $todayClass = $this->scheduledClass($context, 'Read Only Trainer Class', '2026-06-24 09:00:00', '2026-06-24 10:00:00', 8);
        $booking = $this->booking($account, $todayClass, ClassBookingStatus::Booked, 'Клієнтка без відмітки');

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('Read Only Trainer Class')
            ->assertSee('Клієнтка без відмітки')
            ->assertDontSee(route('dashboard.accounts.bookings.update', [$account, $booking]), false);
    }

    public function test_unlinked_trainer_role_gets_profile_empty_state(): void
    {
        $trainerUser = User::factory()->create();
        $account = Account::factory()->create();
        AccountMembership::factory()
            ->for($account)
            ->for($trainerUser, 'user')
            ->create(['role' => AccountRole::Trainer->value, 'permissions' => null]);

        $this->actingAs($trainerUser)
            ->get(route('dashboard.accounts.show', $account))
            ->assertOk()
            ->assertSee('Профіль тренера не привʼязано')
            ->assertDontSee('Активні абонементи')
            ->assertDontSee('Відкриті заявки з сайту');
    }

    /**
     * @return array{account: Account, location: Location, room: Room, classType: ClassType, trainer: Trainer}
     */
    private function classContext(Account $account, string $trainerName = 'Trainer', ?User $trainerUser = null): array
    {
        $location = Location::factory()->for($account)->create(['name' => 'Main Location', 'timezone' => $account->timezone]);
        $room = Room::factory()->for($account)->for($location)->create(['name' => 'Main Hall']);
        $activityDirection = ActivityDirection::factory()->for($account)->create(['color' => '#3B223F']);
        $classType = ClassType::factory()
            ->for($account)
            ->for($activityDirection)
            ->create(['name' => 'Pole', 'color' => '#3B223F']);
        $trainerFactory = Trainer::factory()->for($account);

        if ($trainerUser) {
            $trainerFactory = $trainerFactory->for($trainerUser, 'user');
        }

        $trainer = $trainerFactory->create(['name' => $trainerName]);

        return compact('account', 'location', 'room', 'classType', 'trainer');
    }

    /**
     * @param  array{account: Account, location: Location, room: Room, classType: ClassType, trainer: Trainer}  $context
     */
    private function scheduledClass(array $context, string $title, string $startsAt, string $endsAt, int $capacity, ScheduledClassStatus $status = ScheduledClassStatus::Scheduled): ScheduledClass
    {
        return ScheduledClass::factory()
            ->for($context['account'])
            ->for($context['location'])
            ->for($context['room'])
            ->for($context['classType'])
            ->for($context['trainer'])
            ->create([
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'capacity' => $capacity,
                'status' => $status->value,
            ]);
    }

    private function booking(Account $account, ScheduledClass $scheduledClass, ClassBookingStatus $status, string $customerName): ClassBooking
    {
        $customer = Customer::factory()->for($account)->create(['name' => $customerName]);

        return ClassBooking::factory()
            ->for($account)
            ->for($scheduledClass)
            ->for($customer)
            ->create([
                'booked_by_user_id' => null,
                'status' => $status->value,
            ]);
    }

    private function activePass(Account $account, string $code): CustomerClassPass
    {
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create();

        return CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'code' => $code,
                'status' => CustomerClassPassStatus::Active->value,
                'is_active' => true,
            ]);
    }

    private function inactivePass(Account $account, string $code): CustomerClassPass
    {
        $customer = Customer::factory()->for($account)->create();
        $plan = ClassPassPlan::factory()->for($account)->create();

        return CustomerClassPass::factory()
            ->for($account)
            ->for($customer)
            ->for($plan, 'classPassPlan')
            ->create([
                'code' => $code,
                'status' => CustomerClassPassStatus::UsedUp->value,
                'is_active' => false,
            ]);
    }
}
