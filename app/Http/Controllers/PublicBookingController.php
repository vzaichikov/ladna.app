<?php

namespace App\Http\Controllers;

use App\Actions\CreatePublicBooking;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduledClassStatus;
use App\Enums\ScheduleKind;
use App\Http\Requests\StorePublicBookingRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ScheduledClass;
use App\Support\ManualQuickBookingAvailability;
use App\Support\ScheduleKindRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicBookingController extends Controller
{
    public function show(Request $request, string $accountSlug, string $locationSlug): View|RedirectResponse
    {
        [$account, $location] = $this->publicContext($accountSlug, $locationSlug);
        $customer = $this->currentCustomerFor($account);

        if ($redirect = $this->redirectForRequiredCustomer($request, $account, $customer, $request->fullUrl())) {
            return $redirect;
        }

        try {
            $selection = $this->selectionFromRequest($request, $account, $location, $customer);
        } catch (ValidationException $exception) {
            return redirect()
                ->route('public.schedule', [$account->slug, $location->slug])
                ->withErrors($exception->errors());
        }

        return view('public.booking-confirm', [
            'account' => $account,
            'location' => $location,
            'customer' => $customer,
            'selection' => $selection,
            'allowsGuestBooking' => $account->allowsGuestPublicBooking(),
            'isEmbed' => false,
        ]);
    }

    public function store(
        StorePublicBookingRequest $request,
        string $accountSlug,
        string $locationSlug,
        CreatePublicBooking $createPublicBooking,
    ): RedirectResponse {
        [$account, $location] = $this->publicContext($accountSlug, $locationSlug);
        $customer = $this->currentCustomerFor($account);
        $validated = $request->validated();
        $confirmationUrl = $this->confirmationUrl($account, $location, $validated);

        if ($redirect = $this->redirectForRequiredCustomer($request, $account, $customer, $confirmationUrl)) {
            return $redirect;
        }

        $booking = $createPublicBooking->execute($account, $location, $customer, $validated);

        if ($customer) {
            return redirect()
                ->route('customer.dashboard', $account->slug)
                ->with('status', __('app.booking_created'));
        }

        return redirect()
            ->route('public.schedule', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                ...$this->scheduleReturnQuery($booking->scheduledClass),
            ])
            ->with('status', __('app.booking_created'));
    }

    /**
     * @return array{0: Account, 1: Location}
     */
    private function publicContext(string $accountSlug, string $locationSlug): array
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $this->setAccountLocale($account);

        $location = $account->locations()
            ->active()
            ->where('slug', $locationSlug)
            ->firstOrFail();

        return [$account, $location];
    }

    private function setAccountLocale(Account $account): void
    {
        if (! session()->has('locale')) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }

    private function redirectForRequiredCustomer(Request $request, Account $account, ?Customer $customer, string $intendedUrl): ?RedirectResponse
    {
        if ($customer && ! $customer->profileIsComplete()) {
            session()->put('url.intended', $intendedUrl);

            return redirect()->route('customer.profile.complete', $account->slug);
        }

        if (! $customer && ! $account->allowsGuestPublicBooking()) {
            session()->put('url.intended', $intendedUrl);

            return redirect()->route('customer.studio.login', $account->slug);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionFromRequest(Request $request, Account $account, Location $location, ?Customer $customer): array
    {
        $scheduleKind = ScheduleKind::tryFrom((string) $request->query('schedule_kind'));

        if (! $scheduleKind || ! $account->hasScheduleKindEnabled($scheduleKind)) {
            throw ValidationException::withMessages([
                'schedule_kind' => __('app.manual_class_format_disabled'),
            ]);
        }

        return $scheduleKind === ScheduleKind::GroupClass
            ? $this->groupSelection($request, $account, $location, $customer)
            : $this->manualSelection($request, $account, $location, $scheduleKind);
    }

    /**
     * @return array<string, mixed>
     */
    private function groupSelection(Request $request, Account $account, Location $location, ?Customer $customer): array
    {
        $scheduledClass = $account->scheduledClasses()
            ->with(['classType', 'room', 'trainer'])
            ->whereKey((int) $request->query('scheduled_class_id'))
            ->where('location_id', $location->id)
            ->first();

        if (
            ! $scheduledClass
            || ! $scheduledClass->is_public
            || $scheduledClass->status !== ScheduledClassStatus::Scheduled
            || $scheduledClass->starts_at->lessThan(now())
            || $scheduledClass->classType?->schedule_kind !== ScheduleKind::GroupClass
        ) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.quick_booking_group_class_invalid'),
            ]);
        }

        if (! $scheduledClass->isBookingOpen()) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.booking_cutoff_closed'),
            ]);
        }

        $this->ensureCapacityForSelection($scheduledClass, $customer);
        $timezone = $scheduledClass->displayTimezone();
        $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
        $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);

        return [
            'scheduleKind' => ScheduleKind::GroupClass,
            'title' => $scheduledClass->title,
            'dateLabel' => $startsAt->translatedFormat('l, j F'),
            'timeLabel' => $startsAt->format('H:i').' - '.$endsAt->format('H:i'),
            'durationLabel' => $scheduledClass->durationMinutes().' '.__('app.minutes'),
            'trainerLabel' => $scheduledClass->trainer?->name ?? __('app.trainer_not_assigned'),
            'roomLabel' => $scheduledClass->room?->name ?? $location->name,
            'hiddenFields' => [
                'schedule_kind' => ScheduleKind::GroupClass->value,
                'scheduled_class_id' => $scheduledClass->id,
            ],
            'backUrl' => route('public.schedule', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'kind' => ScheduleKind::GroupClass->value,
                'date' => $startsAt->toDateString(),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualSelection(Request $request, Account $account, Location $location, ScheduleKind $scheduleKind): array
    {
        if (! in_array($scheduleKind, ScheduleKindRegistry::manualKinds(), true)) {
            throw ValidationException::withMessages([
                'schedule_kind' => __('app.manual_class_format_invalid'),
            ]);
        }

        $classType = $account->classTypes()
            ->active()
            ->whereKey((int) $request->query('class_type_id'))
            ->where('schedule_kind', $scheduleKind->value)
            ->first();
        $room = $account->rooms()
            ->active()
            ->whereKey((int) $request->query('room_id'))
            ->where('location_id', $location->id)
            ->first();
        $trainer = filled($request->query('trainer_id'))
            ? $account->trainers()->active()->whereKey((int) $request->query('trainer_id'))->first()
            : null;

        if (! $classType || ! $room) {
            throw ValidationException::withMessages([
                'class_type_id' => __('app.manual_class_format_invalid'),
            ]);
        }

        if ($scheduleKind === ScheduleKind::PrivateLesson && ! $trainer) {
            throw ValidationException::withMessages([
                'trainer_id' => __('app.private_lesson_trainer_required'),
            ]);
        }

        $startsAtValue = (string) $request->query('starts_at');

        if (! app(ManualQuickBookingAvailability::class)->hasStart($account, $scheduleKind, $startsAtValue, [
            'location_id' => $location->id,
            'room_id' => $room->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer?->id,
        ])) {
            throw ValidationException::withMessages([
                'starts_at' => __('app.manual_slot_unavailable'),
            ]);
        }

        $timezone = $location->timezone ?? $account->timezone ?? config('app.timezone');
        $startsAt = Carbon::createFromFormat('Y-m-d\TH:i', $startsAtValue, $timezone);
        $durationMinutes = (int) ($classType->default_duration_minutes ?: 60);
        $endsAt = $startsAt->copy()->addMinutes($durationMinutes);

        return [
            'scheduleKind' => $scheduleKind,
            'title' => $classType->name,
            'dateLabel' => $startsAt->translatedFormat('l, j F'),
            'timeLabel' => $startsAt->format('H:i').' - '.$endsAt->format('H:i'),
            'durationLabel' => $durationMinutes.' '.__('app.minutes'),
            'trainerLabel' => $trainer?->name,
            'roomLabel' => $room->name,
            'hiddenFields' => [
                'schedule_kind' => $scheduleKind->value,
                'date' => $startsAt->toDateString(),
                'starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'class_type_id' => $classType->id,
                'room_id' => $room->id,
                'trainer_id' => $trainer?->id,
            ],
            'backUrl' => route('public.schedule', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'kind' => $scheduleKind->value,
                'date' => $startsAt->toDateString(),
                'class_type' => $classType->id,
                'room' => $room->id,
                'trainer' => $trainer?->id,
            ]),
        ];
    }

    private function ensureCapacityForSelection(ScheduledClass $scheduledClass, ?Customer $customer): void
    {
        $activeStatuses = [
            ClassBookingStatus::Booked->value,
            ClassBookingStatus::Attended->value,
        ];

        if ($customer && $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->where('customer_id', $customer->id)
            ->whereIn('status', $activeStatuses)
            ->exists()) {
            return;
        }

        $capacity = (int) ($scheduledClass->capacity ?? 0);
        $activeBookingsCount = $scheduledClass->classBookings()
            ->notCorrectedRemoved()
            ->whereIn('status', $activeStatuses)
            ->count();

        if ($capacity <= 0 || $activeBookingsCount >= $capacity) {
            throw ValidationException::withMessages([
                'scheduled_class_id' => __('app.no_available_group_slots'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function confirmationUrl(Account $account, Location $location, array $validated): string
    {
        $query = collect($validated)
            ->only(['schedule_kind', 'scheduled_class_id', 'date', 'starts_at', 'class_type_id', 'room_id', 'trainer_id'])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();

        return route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            ...$query,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function scheduleReturnQuery(ScheduledClass $scheduledClass): array
    {
        $scheduledClass->loadMissing('classType');
        $scheduleKind = $scheduledClass->classType?->schedule_kind ?? ScheduleKind::GroupClass;
        $date = $scheduledClass->starts_at
            ->copy()
            ->timezone($scheduledClass->displayTimezone())
            ->toDateString();

        return [
            'kind' => $scheduleKind->value,
            'date' => $date,
        ];
    }

    private function currentCustomerFor(Account $account): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer && $customer->account_id === $account->id ? $customer : null;
    }
}
