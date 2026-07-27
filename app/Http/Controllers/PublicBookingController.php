<?php

namespace App\Http\Controllers;

use App\Actions\CreatePublicBooking;
use App\Actions\ResolvePublicGroupBookingSelection;
use App\Enums\ScheduleKind;
use App\Http\Requests\StorePublicBookingRequest;
use App\Models\Account;
use App\Models\AccountOnboarding;
use App\Models\Customer;
use App\Models\Location;
use App\Models\ScheduledClass;
use App\Support\ManualQuickBookingAvailability;
use App\Support\RoomActivityDirectionEligibility;
use App\Support\ScheduleKindRegistry;
use App\Support\TrainerActivityDirectionEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

use function Illuminate\Support\defer;

class PublicBookingController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SCHEDULE_RETURN_QUERY_KEYS = [
        'period',
        'room',
        'kind',
        'date',
        'month',
        'activity_direction',
        'class_type',
        'trainer',
        'display',
    ];

    public function __construct(
        private readonly RoomActivityDirectionEligibility $roomActivityDirectionEligibility,
        private readonly ResolvePublicGroupBookingSelection $resolvePublicGroupBookingSelection,
    ) {}

    public function show(Request $request, string $accountSlug, string $locationSlug): View|RedirectResponse|JsonResponse
    {
        [$account, $location] = $this->publicContext($accountSlug, $locationSlug);
        $customer = $this->currentCustomerFor($account);
        $usesModalPresentation = $this->usesModalPresentation($request, $account, $location);
        $intendedUrl = $usesModalPresentation
            ? $this->modalReturnUrl($request, $account, $location)
            : $request->fullUrl();

        if ($redirect = $this->redirectForRequiredCustomer($request, $account, $customer, $intendedUrl)) {
            return $usesModalPresentation && $request->ajax()
                ? response()->json(['redirect_url' => $redirect->getTargetUrl()], 409)
                : $redirect;
        }

        try {
            $selection = $this->selectionFromRequest($request, $account, $location, $customer);
        } catch (ValidationException $exception) {
            if ($usesModalPresentation && $request->ajax()) {
                return response()->json([
                    'message' => collect($exception->errors())->flatten()->first(),
                    'redirect_url' => $this->modalReturnUrl($request, $account, $location),
                ], 422);
            }

            return redirect()
                ->route('public.schedule', [$account->slug, $location->slug])
                ->withErrors($exception->errors());
        }

        $viewData = [
            'account' => $account,
            'location' => $location,
            'customer' => $customer,
            'selection' => $selection,
            'allowsGuestBooking' => $account->allowsGuestPublicBooking(),
            'isEmbed' => false,
            'isModal' => $usesModalPresentation,
            'returnUrl' => $this->scheduleReturnUrl($request, $account, $location),
        ];

        return view($usesModalPresentation && $request->ajax()
            ? 'public._booking-modal'
            : 'public.booking-confirm', $viewData);
    }

    public function store(
        StorePublicBookingRequest $request,
        string $accountSlug,
        string $locationSlug,
        CreatePublicBooking $createPublicBooking,
    ): RedirectResponse|JsonResponse {
        [$account, $location] = $this->publicContext($accountSlug, $locationSlug);
        $customer = $this->currentCustomerFor($account);
        $validated = $request->validated();
        $usesModalPresentation = $this->usesModalPresentation($request, $account, $location);
        $confirmationUrl = $this->confirmationUrl($account, $location, $validated);
        $intendedUrl = $usesModalPresentation
            ? $this->modalReturnUrl($request, $account, $location)
            : $confirmationUrl;

        if ($usesModalPresentation && ! $customer && $request->boolean('customer_session_expected')) {
            session()->put('url.intended', $intendedUrl);
            $loginUrl = route('customer.studio.login', $account->slug);

            return $request->expectsJson()
                ? response()->json(['redirect_url' => $loginUrl], 409)
                : redirect()->to($loginUrl);
        }

        if ($redirect = $this->redirectForRequiredCustomer($request, $account, $customer, $intendedUrl)) {
            return $usesModalPresentation && $request->expectsJson()
                ? response()->json(['redirect_url' => $redirect->getTargetUrl()], 409)
                : $redirect;
        }

        $selection = $usesModalPresentation
            ? $this->selectionFromValidated($validated, $account, $location, $customer)
            : null;
        $booking = $createPublicBooking->execute($account, $location, $customer, $validated);
        $this->recordFirstOnboardingBooking($account);

        if ($usesModalPresentation && $request->expectsJson() && $selection) {
            $cabinetUrl = $customer
                ? route('customer.dashboard', $account->slug)
                : route('customer.studio.login', $account->slug);
            $continueUrl = $this->scheduleReturnUrl($request, $account, $location);

            return response()->json([
                'message' => __('app.booking_created'),
                'modal_title' => __('app.booking_confirmed_title'),
                'booking_id' => $booking->id,
                'success_html' => view('public._booking-success', [
                    'selection' => $selection,
                    'cabinetUrl' => $cabinetUrl,
                    'continueUrl' => $continueUrl,
                ])->render(),
                'continue_url' => $continueUrl,
            ]);
        }

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

    private function recordFirstOnboardingBooking(Account $account): void
    {
        defer(function () use ($account): void {
            try {
                AccountOnboarding::query()
                    ->whereBelongsTo($account)
                    ->first()?->recordMetricOnce('first_booking_at');
            } catch (Throwable $exception) {
                report($exception);
            }
        });
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
            ? $this->resolvePublicGroupBookingSelection->resolve(
                $account,
                $location,
                $customer,
                (int) $request->query('scheduled_class_id'),
            )
            : $this->manualSelection($request, $account, $location, $scheduleKind);
    }

    /**
     * @return array<string, mixed>
     */
    private function selectionFromValidated(array $validated, Account $account, Location $location, ?Customer $customer): array
    {
        return $this->resolvePublicGroupBookingSelection->resolve(
            $account,
            $location,
            $customer,
            (int) ($validated['scheduled_class_id'] ?? 0),
        );
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
        $trainerActivityDirectionEligibility = app(TrainerActivityDirectionEligibility::class);
        $activityDirectionId = $trainerActivityDirectionEligibility->activeDirectionId($account, $request->query('activity_direction_id'));

        if (! $classType || ! $room) {
            throw ValidationException::withMessages([
                'class_type_id' => __('app.manual_class_format_invalid'),
            ]);
        }

        if (! $this->roomActivityDirectionEligibility->roomCanHost($account, $room, $classType, $activityDirectionId)) {
            throw ValidationException::withMessages([
                'room_id' => __('app.room_activity_direction_mismatch'),
            ]);
        }

        if ($scheduleKind === ScheduleKind::PrivateLesson && ! $trainer) {
            throw ValidationException::withMessages([
                'trainer_id' => __('app.private_lesson_trainer_required'),
            ]);
        }

        $effectiveActivityDirectionId = $scheduleKind === ScheduleKind::PrivateLesson
            ? $trainerActivityDirectionEligibility->effectiveDirectionId($account, $classType, $activityDirectionId)
            : null;

        if (
            $scheduleKind === ScheduleKind::PrivateLesson
            && $trainerActivityDirectionEligibility->accountHasActiveDirections($account)
            && ! $effectiveActivityDirectionId
        ) {
            throw ValidationException::withMessages([
                'activity_direction_id' => __('app.private_lesson_activity_direction_required'),
            ]);
        }

        if (
            $scheduleKind === ScheduleKind::PrivateLesson
            && $trainer
            && ! $trainerActivityDirectionEligibility->trainerCanHandle($account, $trainer, $classType, $activityDirectionId)
        ) {
            throw ValidationException::withMessages([
                'trainer_id' => __('app.trainer_activity_direction_mismatch'),
            ]);
        }

        $startsAtValue = (string) $request->query('starts_at');

        if (! app(ManualQuickBookingAvailability::class)->hasStart($account, $scheduleKind, $startsAtValue, [
            'location_id' => $location->id,
            'room_id' => $room->id,
            'class_type_id' => $classType->id,
            'trainer_id' => $trainer?->id,
            'activity_direction_id' => $activityDirectionId,
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
            'peopleCount' => $scheduleKind === ScheduleKind::PrivateLesson
                ? max(1, (int) $request->query('people_count', 1))
                : null,
            'hiddenFields' => [
                'schedule_kind' => $scheduleKind->value,
                'date' => $startsAt->toDateString(),
                'starts_at' => $startsAt->format('Y-m-d\TH:i'),
                'class_type_id' => $classType->id,
                'activity_direction_id' => $activityDirectionId,
                'room_id' => $room->id,
                'trainer_id' => $trainer?->id,
            ],
            'backUrl' => route('public.schedule', [
                'accountSlug' => $account->slug,
                'locationSlug' => $location->slug,
                'kind' => $scheduleKind->value,
                'date' => $startsAt->toDateString(),
                'activity_direction' => $activityDirectionId,
                'class_type' => $classType->id,
                'room' => $room->id,
                'trainer' => $trainer?->id,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function confirmationUrl(Account $account, Location $location, array $validated): string
    {
        $query = collect($validated)
            ->only(['schedule_kind', 'scheduled_class_id', 'date', 'starts_at', 'class_type_id', 'activity_direction_id', 'room_id', 'trainer_id', 'people_count'])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();

        return route('public.booking.show', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            ...$query,
        ]);
    }

    private function usesModalPresentation(Request $request, Account $account, Location $location): bool
    {
        return $request->query('presentation') === 'modal'
            && $request->input('schedule_kind') === ScheduleKind::GroupClass->value
            && $account->usesPublicGroupBookingModal()
            && $this->hasValidScheduleReturnUrl($request, $account, $location);
    }

    private function modalReturnUrl(Request $request, Account $account, Location $location): string
    {
        return $this->scheduleReturnUrl(
            $request,
            $account,
            $location,
            (int) $request->input('scheduled_class_id'),
        );
    }

    private function scheduleReturnUrl(Request $request, Account $account, Location $location, ?int $bookingId = null): string
    {
        $returnTo = $request->input('return_to');
        $query = [];

        if (is_string($returnTo) && $returnTo !== '') {
            $candidateParts = parse_url($returnTo);
            $expectedParts = parse_url(route('public.schedule', [$account->slug, $location->slug]));

            if (is_array($candidateParts) && is_array($expectedParts)) {
                $candidateHost = $candidateParts['host'] ?? request()->getHost();

                if (
                    ($candidateParts['path'] ?? null) === ($expectedParts['path'] ?? null)
                    && $candidateHost === request()->getHost()
                ) {
                    parse_str((string) ($candidateParts['query'] ?? ''), $candidateQuery);
                    $query = collect($candidateQuery)
                        ->only(self::SCHEDULE_RETURN_QUERY_KEYS)
                        ->filter(fn (mixed $value): bool => is_scalar($value) && $value !== '')
                        ->all();
                }
            }
        }

        if ($bookingId && $bookingId > 0) {
            $query['booking'] = $bookingId;
        }

        return route('public.schedule', [
            'accountSlug' => $account->slug,
            'locationSlug' => $location->slug,
            ...$query,
        ]);
    }

    private function hasValidScheduleReturnUrl(Request $request, Account $account, Location $location): bool
    {
        $returnTo = $request->input('return_to');

        if (! is_string($returnTo) || $returnTo === '') {
            return false;
        }

        $candidateParts = parse_url($returnTo);
        $expectedParts = parse_url(route('public.schedule', [$account->slug, $location->slug]));

        if (! is_array($candidateParts) || ! is_array($expectedParts)) {
            return false;
        }

        return ($candidateParts['path'] ?? null) === ($expectedParts['path'] ?? null)
            && ($candidateParts['host'] ?? request()->getHost()) === request()->getHost();
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
