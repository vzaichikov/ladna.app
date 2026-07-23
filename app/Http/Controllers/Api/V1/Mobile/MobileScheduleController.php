<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Enums\AccountRole;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Enums\StudioPermission;
use App\Http\Controllers\Controller;
use App\Models\MobileSession;
use App\Models\ScheduledClass;
use App\Support\Mobile\MobileScheduledClassPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileScheduleController extends Controller
{
    public function index(Request $request, MobileScheduledClassPayload $payload): JsonResponse
    {
        $session = $this->session($request);
        $account = $session->account;
        $from = $request->date('from') ?: now()->startOfDay();
        $to = $request->date('to') ?: now()->addDays(14)->endOfDay();
        $customer = $session->guard === MobileSession::GuardCustomer ? $session->customer : null;

        $classes = $account->scheduledClasses()
            ->with([
                'location',
                'room',
                'classType.activityDirection',
                'trainer',
                'additionalTrainers',
                'classBookings.customer',
                'classBookings.classPassReservation.customerClassPass',
            ])
            ->whereBetween('starts_at', [$from, $to])
            ->when($request->integer('location_id') > 0, fn (Builder $query): Builder => $query->where('location_id', $request->integer('location_id')))
            ->when($session->guard === MobileSession::GuardCustomer, function (Builder $query): Builder {
                return $query
                    ->where('is_public', true)
                    ->where('status', ScheduledClassStatus::Scheduled->value)
                    ->whereHas('classType', fn (Builder $query): Builder => $query->where('schedule_kind', ScheduleKind::GroupClass->value));
            })
            ->when($session->guard === MobileSession::GuardStaff && $session->role === AccountRole::Trainer->value, function (Builder $query) use ($account, $session): Builder {
                $trainerId = $account->trainers()->where('user_id', $session->user_id)->value('id');

                return $trainerId
                    ? $query->where(function (Builder $query) use ($trainerId): void {
                        $query
                            ->where('trainer_id', $trainerId)
                            ->orWhereHas('additionalTrainers', fn (Builder $query): Builder => $query->whereKey($trainerId));
                    })
                    : $query->whereRaw('1 = 0');
            })
            ->orderBy('starts_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $classes
                ->map(fn (ScheduledClass $scheduledClass): array => $payload->forClass(
                    $scheduledClass,
                    $customer,
                    includeBookings: $this->canViewStaffBookingDetails($session),
                ))
                ->values(),
        ]);
    }

    public function show(Request $request, ScheduledClass $scheduledClass, MobileScheduledClassPayload $payload): JsonResponse
    {
        $session = $this->session($request);

        abort_unless($scheduledClass->account_id === $session->account_id, 404);
        $this->ensureClassVisibleToSession($session, $scheduledClass);

        $scheduledClass->load([
            'location',
            'room',
            'classType.activityDirection',
            'trainer',
            'additionalTrainers',
            'classBookings.customer',
            'classBookings.classPassReservation.customerClassPass',
        ]);

        return response()->json([
            'data' => $payload->forClass(
                $scheduledClass,
                $session->guard === MobileSession::GuardCustomer ? $session->customer : null,
                includeBookings: $this->canViewStaffBookingDetails($session),
            ),
        ]);
    }

    private function session(Request $request): MobileSession
    {
        return $request->attributes->get('mobileSession');
    }

    private function ensureClassVisibleToSession(MobileSession $session, ScheduledClass $scheduledClass): void
    {
        $scheduledClass->loadMissing('classType');

        if ($session->guard === MobileSession::GuardCustomer) {
            abort_unless(
                $scheduledClass->is_public
                    && $scheduledClass->status === ScheduledClassStatus::Scheduled
                    && $scheduledClass->classType?->schedule_kind === ScheduleKind::GroupClass,
                404
            );

            return;
        }

        if ($session->guard === MobileSession::GuardStaff && $session->role === AccountRole::Trainer->value) {
            $trainerId = $session->account->trainers()->where('user_id', $session->user_id)->value('id');

            abort_unless($trainerId && $scheduledClass->isAssignedToTrainer($trainerId), 404);
        }
    }

    private function canViewStaffBookingDetails(MobileSession $session): bool
    {
        if ($session->guard !== MobileSession::GuardStaff || ! $session->user) {
            return false;
        }

        return $session->account->userCan($session->user, StudioPermission::ManageBookings)
            || $session->account->userCan($session->user, StudioPermission::ManageClients);
    }
}
