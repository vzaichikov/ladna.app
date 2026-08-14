<?php

namespace App\Support\Telegram;

use App\Actions\CancelClassBooking;
use App\Actions\CreatePublicBooking;
use App\Enums\AccountMode;
use App\Enums\AccountStatus;
use App\Enums\ClassBookingStatus;
use App\Enums\ScheduleKind;
use App\Enums\TelegramBotMode;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Enums\TelegramCustomerSessionState;
use App\Models\Account;
use App\Models\ActivityDirection;
use App\Models\ClassBooking;
use App\Models\ClassType;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduledClass;
use App\Models\TelegramChatAuthorization;
use App\Models\TelegramCustomerSession;
use App\Models\TelegramMessage;
use App\Models\TelegramUpdate;
use App\Models\Trainer;
use App\Support\ClassBookingCancellationWindow;
use App\Support\CustomerAuth\TelegramCustomerLoginTokenService;
use App\Support\PhoneNumberNormalizer;
use App\Support\SaasBilling\AccountSubscriptionAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CustomerTelegramUpdateProcessor
{
    private const SessionMinutes = 30;

    private const ConfirmationMinutes = 10;

    private const ScheduleDays = 28;

    private const DatePageSize = 7;

    private const ListPageSize = 5;

    public function __construct(
        private readonly TelegramClient $telegramClient,
        private readonly PhoneNumberNormalizer $phones,
        private readonly AccountSubscriptionAccess $subscriptionAccess,
        private readonly CreatePublicBooking $createPublicBooking,
        private readonly CancelClassBooking $cancelClassBooking,
        private readonly ClassBookingCancellationWindow $cancellationWindow,
        private readonly TelegramCustomerLoginTokenService $customerLoginTokens,
        private readonly CustomerTelegramPrivateLessonOptions $privateLessonOptions,
    ) {}

    public function handle(TelegramUpdate $telegramUpdate): bool
    {
        $telegramUpdate->loadMissing('installation.account');
        $installation = $telegramUpdate->installation;
        $account = $installation?->account;

        if (! $installation || ! $account || $installation->profile !== TelegramBotProfile::Customer) {
            return false;
        }

        $payload = $this->payloadContext($telegramUpdate);

        if (! $payload) {
            return false;
        }

        if ($payload['chat_type'] !== 'private' || $payload['chat_id'] === '' || $payload['telegram_user_id'] === '') {
            return true;
        }

        $rateLimitKey = 'telegram-customer:'.$installation->id.':'.$payload['chat_id'];

        if (RateLimiter::tooManyAttempts($rateLimitKey, 60)) {
            return true;
        }

        RateLimiter::hit($rateLimitKey, 60);

        $lockKey = 'telegram-customer-session:'.hash('sha256', $installation->id.':'.$payload['chat_id']);

        return Cache::lock($lockKey, 30)->block(5, function () use ($telegramUpdate, $account, $payload): bool {
            if (! $this->accountCanUseBot($account)) {
                $this->send($telegramUpdate, $payload['chat_id'], __('app.telegram_customer_bot_unavailable', [], $this->localeFromTelegram($account, $payload['language_code'])));

                return true;
            }

            $session = $this->sessionFor($telegramUpdate, $payload);
            $authorization = $this->currentAuthorization($telegramUpdate, $session, $payload);

            if ($payload['kind'] === 'callback') {
                return $this->processCallback($telegramUpdate, $session, $authorization, $payload);
            }

            $this->storeInbound($telegramUpdate, $authorization, $payload);

            return $this->processMessage($telegramUpdate, $session, $authorization, $payload);
        });
    }

    /**
     * @param  array{kind: string, chat_id: string, chat_type: string, telegram_user_id: string, username: string, language_code: string, text: string, message_id: string, message: array<string, mixed>, callback_id: string, callback_data: string}  $payload
     */
    private function processMessage(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, ?TelegramChatAuthorization $authorization, array $payload): bool
    {
        $command = $this->command($payload['text']);

        if ($command === 'start') {
            $this->resetSession($session, $authorization ? TelegramCustomerSessionState::Idle : TelegramCustomerSessionState::AwaitingContact);

            if ($authorization) {
                $welcome = $telegramUpdate->installation->account->telegramBotProfiles()
                    ->where('profile', TelegramBotProfile::Customer->value)
                    ->value('welcome_message');
                $text = filled($welcome)
                    ? (string) $welcome
                    : $this->t($session, 'telegram_customer_welcome', ['name' => $authorization->customer?->name ?: $this->t($session, 'customer')]);
                $this->showMainMenu($telegramUpdate, $session, $authorization, $text);
            } else {
                $this->requestContact($telegramUpdate, $session);
            }

            return true;
        }

        if ($command === 'cancel') {
            $this->resetSession($session, $authorization ? TelegramCustomerSessionState::Idle : TelegramCustomerSessionState::AwaitingContact);

            if ($authorization) {
                $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_action_cancelled'));
            } else {
                $this->requestContact($telegramUpdate, $session);
            }

            return true;
        }

        if (is_array(data_get($payload, 'message.contact'))) {
            if ($authorization) {
                $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_already_linked'));

                return true;
            }

            return $this->processContact($telegramUpdate, $session, $payload);
        }

        if (! $authorization) {
            if ($session->state === TelegramCustomerSessionState::AwaitingFullName && $payload['text'] !== '') {
                return $this->processFullName($telegramUpdate, $session, $payload['text']);
            }

            $this->requestContact($telegramUpdate, $session);

            return true;
        }

        $action = $this->menuAction($payload['text'], $command);

        return match ($action) {
            'book' => $this->beginBooking($telegramUpdate, $session, $authorization),
            'bookings' => $this->showBookings($telegramUpdate, $session, $authorization),
            'passes' => $this->showPasses($telegramUpdate, $session, $authorization, 0, false),
            'attendance' => $this->showAttendance($telegramUpdate, $session, $authorization, 0),
            'studio' => $this->showStudio($telegramUpdate, $session, $authorization),
            'language' => $this->showSettings($telegramUpdate, $session, $authorization),
            'unlink' => $this->showUnlinkConfirmation($telegramUpdate, $session, $authorization),
            default => $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_choose_menu_action')),
        };
    }

    /**
     * @param  array{kind: string, chat_id: string, chat_type: string, telegram_user_id: string, username: string, language_code: string, text: string, message_id: string, message: array<string, mixed>, callback_id: string, callback_data: string}  $payload
     */
    private function processCallback(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, ?TelegramChatAuthorization $authorization, array $payload): bool
    {
        $this->telegramClient->answerCallbackQuery($telegramUpdate->installation, $payload['callback_id']);
        $callback = $this->callbackFor($session, $payload['callback_data']);

        if (! $callback) {
            if ($authorization) {
                $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_button_expired'));
            } else {
                $this->requestContact($telegramUpdate, $session, $this->t($session, 'telegram_customer_button_expired'));
            }

            return true;
        }

        $action = (string) $callback['action'];
        $value = $callback['value'] ?? null;

        if (in_array($action, ['confirm_customer', 'confirm_booking', 'confirm_private_booking', 'cancel_booking', 'confirm_unlink'], true)) {
            $confirmationKey = 'telegram-customer-confirm:'.$telegramUpdate->telegram_bot_installation_id.':'.$session->telegram_chat_id;

            if (RateLimiter::tooManyAttempts($confirmationKey, 10)) {
                $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_too_many_actions'), [], $authorization);

                return true;
            }

            RateLimiter::hit($confirmationKey, 60);
        }

        if (! $authorization && ! in_array($action, ['confirm_customer', 'edit_customer'], true)) {
            $this->requestContact($telegramUpdate, $session);

            return true;
        }

        return match ($action) {
            'confirm_customer' => $this->confirmCustomer($telegramUpdate, $session, $payload),
            'edit_customer' => $this->askForFullName($telegramUpdate, $session),
            'menu' => $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_choose_menu_action')),
            'bookings' => $this->showBookings($telegramUpdate, $session, $authorization),
            'booking_types' => $this->showBookingTypes($telegramUpdate, $session, $authorization, true),
            'book_type' => $this->beginBookingType($telegramUpdate, $session, $authorization, (string) $value),
            'book_locations' => $this->beginGroupBooking($telegramUpdate, $session, $authorization, null, true),
            'book_location' => $this->showBookingDates($telegramUpdate, $session, $authorization, (int) $value, 0),
            'book_dates_page' => $this->showBookingDates($telegramUpdate, $session, $authorization, (int) data_get($session->encrypted_context, 'location_id'), (int) $value),
            'book_date' => $this->showClassesForDate($telegramUpdate, $session, $authorization, (string) $value),
            'book_class' => $this->showBookingConfirmation($telegramUpdate, $session, $authorization, (int) $value),
            'confirm_booking' => $this->confirmBooking($telegramUpdate, $session, $authorization, (int) $value),
            'private_locations' => $this->beginPrivateBooking($telegramUpdate, $session, $authorization, true),
            'private_location' => $this->showPrivateDirections($telegramUpdate, $session, $authorization, (int) $value),
            'private_directions' => $this->showPrivateDirections($telegramUpdate, $session, $authorization, (int) data_get($session->encrypted_context, 'private_location_id'), false),
            'private_direction' => $this->showPrivateServices($telegramUpdate, $session, $authorization, (int) data_get($session->encrypted_context, 'private_location_id'), (int) $value),
            'private_services' => $this->showPrivateServices($telegramUpdate, $session, $authorization, (int) data_get($session->encrypted_context, 'private_location_id'), $this->privateContextId($session, 'private_direction_id'), false),
            'private_service' => $this->showPrivateTrainers($telegramUpdate, $session, $authorization, (int) data_get($session->encrypted_context, 'private_location_id'), $this->privateContextId($session, 'private_direction_id'), (int) $value),
            'private_trainers' => $this->showPrivateTrainers($telegramUpdate, $session, $authorization, (int) data_get($session->encrypted_context, 'private_location_id'), $this->privateContextId($session, 'private_direction_id'), (int) data_get($session->encrypted_context, 'private_class_type_id'), false),
            'private_trainer' => $this->continuePrivateAfterTrainer($telegramUpdate, $session, $authorization, (int) data_get($session->encrypted_context, 'private_location_id'), $this->privateContextId($session, 'private_direction_id'), (int) data_get($session->encrypted_context, 'private_class_type_id'), (int) $value),
            'private_rooms' => $this->showPrivateRooms($telegramUpdate, $session, $authorization, $this->privateSelectionFromSession($session), false),
            'private_room' => $this->selectPrivateRoom($telegramUpdate, $session, $authorization, (int) $value),
            'private_dates' => $this->showPrivateDates($telegramUpdate, $session, $authorization, $this->privateSelectionFromSession($session), (int) data_get($session->encrypted_context, 'private_date_page', 0)),
            'private_dates_page' => $this->showPrivateDates($telegramUpdate, $session, $authorization, $this->privateSelectionFromSession($session), (int) $value),
            'private_date' => $this->showPrivateTimes($telegramUpdate, $session, $authorization, $this->privateSelectionFromSession($session), (string) $value),
            'private_times' => $this->showPrivateTimes($telegramUpdate, $session, $authorization, $this->privateSelectionFromSession($session), (string) data_get($session->encrypted_context, 'private_date')),
            'private_time' => $this->selectPrivateTime($telegramUpdate, $session, $authorization, (string) $value),
            'private_slot_rooms' => $this->showPrivateSlotRooms($telegramUpdate, $session, $authorization, $this->privateSelectionFromSession($session), (string) data_get($session->encrypted_context, 'private_starts_at'), false),
            'private_slot_room' => $this->showPrivateBookingConfirmation($telegramUpdate, $session, $authorization, [...$this->privateSelectionFromSession($session), 'room_id' => (int) $value], (string) data_get($session->encrypted_context, 'private_starts_at')),
            'confirm_private_booking' => $this->confirmPrivateBooking($telegramUpdate, $session, $authorization),
            'booking_detail' => $this->showBookingDetail($telegramUpdate, $session, $authorization, (int) $value),
            'booking_history' => $this->showBookingHistory($telegramUpdate, $session, $authorization, (int) $value),
            'confirm_cancel_booking' => $this->showCancellationConfirmation($telegramUpdate, $session, $authorization, (int) $value),
            'cancel_booking' => $this->confirmCancellation($telegramUpdate, $session, $authorization, (int) $value),
            'passes_page' => $this->showPasses($telegramUpdate, $session, $authorization, (int) data_get($value, 'page', 0), (bool) data_get($value, 'history', false)),
            'attendance_page' => $this->showAttendance($telegramUpdate, $session, $authorization, (int) $value),
            'settings' => $this->showSettings($telegramUpdate, $session, $authorization),
            'set_language' => $this->setLanguage($telegramUpdate, $session, $authorization, (string) $value),
            'unlink' => $this->showUnlinkConfirmation($telegramUpdate, $session, $authorization),
            'confirm_unlink' => $this->unlink($telegramUpdate, $session, $authorization),
            default => $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_button_expired')),
        };
    }

    /**
     * @param  array{kind: string, chat_id: string, chat_type: string, telegram_user_id: string, username: string, language_code: string, text: string, message_id: string, message: array<string, mixed>, callback_id: string, callback_data: string}  $payload
     */
    private function processContact(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, array $payload): bool
    {
        $contact = data_get($payload, 'message.contact');
        $contactUserId = (string) data_get($contact, 'user_id', '');

        if ($contactUserId === '' || ! hash_equals($payload['telegram_user_id'], $contactUserId)) {
            $this->requestContact($telegramUpdate, $session, $this->t($session, 'telegram_customer_contact_must_be_own'));

            return true;
        }

        $account = $telegramUpdate->installation->account;
        $phone = $this->phones->normalize((string) data_get($contact, 'phone_number', ''), $account->country_code ?? 'UA');

        if (! $this->phones->isValid($phone, $account->country_code ?? 'UA')) {
            $this->requestContact($telegramUpdate, $session, $this->t($session, 'telegram_customer_phone_invalid'));

            return true;
        }

        $customer = $account->customers()->where('phone', $phone)->first();

        if ($customer) {
            $authorization = $this->linkCustomer($telegramUpdate, $session, $customer, $phone, $payload);
            $this->showMainMenu(
                $telegramUpdate,
                $session,
                $authorization,
                $this->t($session, 'telegram_customer_welcome', ['name' => $customer->name ?: $this->t($session, 'customer')]),
            );

            return true;
        }

        $session->forceFill([
            'state' => TelegramCustomerSessionState::AwaitingFullName->value,
            'encrypted_context' => ['pending_phone' => $phone],
            'expires_at' => now()->addMinutes(self::SessionMinutes),
            'last_interaction_at' => now(),
        ])->save();
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_ask_full_name'), [
            'reply_markup' => ['remove_keyboard' => true],
        ]);

        return true;
    }

    private function processFullName(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, string $fullName): bool
    {
        $fullName = Str::of($fullName)->squish()->toString();

        if (! $this->validFullName($fullName)) {
            $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_full_name_invalid'));

            return true;
        }

        $phone = (string) data_get($session->encrypted_context, 'pending_phone', '');

        if ($phone === '') {
            $this->requestContact($telegramUpdate, $session, $this->t($session, 'telegram_customer_session_expired'));

            return true;
        }

        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ConfirmingCustomer,
            [[
                ['text' => $this->t($session, 'confirm'), 'action' => 'confirm_customer'],
                ['text' => $this->t($session, 'back'), 'action' => 'edit_customer'],
            ]],
            ['pending_phone' => $phone, 'pending_name' => $fullName],
            self::ConfirmationMinutes,
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_confirm_profile', [
            'name' => $fullName,
            'phone' => $phone,
        ]), $markup);

        return true;
    }

    /**
     * @param  array{telegram_user_id: string, username: string}  $payload
     */
    private function confirmCustomer(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, array $payload): bool
    {
        $phone = (string) data_get($session->encrypted_context, 'pending_phone', '');
        $name = (string) data_get($session->encrypted_context, 'pending_name', '');

        if ($phone === '' || ! $this->validFullName($name)) {
            $this->requestContact($telegramUpdate, $session, $this->t($session, 'telegram_customer_session_expired'));

            return true;
        }

        $account = $telegramUpdate->installation->account;
        $customer = DB::transaction(function () use ($telegramUpdate, $account, $phone, $name, $session): Customer {
            $telegramUpdate->installation->newQuery()
                ->whereKey($telegramUpdate->telegram_bot_installation_id)
                ->lockForUpdate()
                ->firstOrFail();
            $customer = $account->customers()->where('phone', $phone)->lockForUpdate()->first();

            if (! $customer) {
                $customer = $account->customers()->create([
                    'name' => $name,
                    'phone' => $phone,
                    'default_language' => $session->locale,
                    'phone_verified_at' => now(),
                ]);
            } else {
                $customer->forceFill([
                    'phone_verified_at' => $customer->phone_verified_at ?? now(),
                    'default_language' => $customer->default_language ?: $session->locale,
                ])->save();
            }

            return $customer;
        }, attempts: 3);
        $authorization = $this->linkCustomer($telegramUpdate, $session, $customer, $phone, $payload);
        $this->showMainMenu(
            $telegramUpdate,
            $session,
            $authorization,
            $this->t($session, 'telegram_customer_created_welcome', ['name' => $customer->name]),
        );

        return true;
    }

    private function askForFullName(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session): bool
    {
        $phone = (string) data_get($session->encrypted_context, 'pending_phone', '');
        $session->forceFill([
            'state' => TelegramCustomerSessionState::AwaitingFullName->value,
            'encrypted_context' => ['pending_phone' => $phone],
            'expires_at' => now()->addMinutes(self::SessionMinutes),
        ])->save();
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_ask_full_name'));

        return true;
    }

    private function beginBooking(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization): bool
    {
        return $this->showBookingTypes($telegramUpdate, $session, $authorization);
    }

    private function showBookingTypes(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, bool $forceChoice = false): bool
    {
        $groupClasses = $this->eligibleClasses($authorization->account, $authorization->customer);
        $groupAvailable = $groupClasses->isNotEmpty();
        $privateAvailable = $this->privateLessonOptions->isConfigured($authorization->account);

        if (! $forceChoice && $groupAvailable && ! $privateAvailable) {
            return $this->beginGroupBooking($telegramUpdate, $session, $authorization, $groupClasses);
        }

        if (! $forceChoice && $privateAvailable && ! $groupAvailable) {
            return $this->beginPrivateBooking($telegramUpdate, $session, $authorization);
        }

        $rows = [];

        if ($groupAvailable) {
            $rows[] = [[
                'text' => $this->t($session, 'telegram_customer_booking_type_group'),
                'action' => 'book_type',
                'value' => ScheduleKind::GroupClass->value,
            ]];
        }

        if ($privateAvailable) {
            $rows[] = [[
                'text' => $this->t($session, 'telegram_customer_booking_type_private'),
                'action' => 'book_type',
                'value' => ScheduleKind::PrivateLesson->value,
            ]];
        }

        if ($rows === []) {
            return $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_no_available_classes'));
        }

        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::ChoosingBookingType, $rows);
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_choose_booking_type'), $markup, $authorization);

        return true;
    }

    private function beginBookingType(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, string $scheduleKind): bool
    {
        return match ($scheduleKind) {
            ScheduleKind::GroupClass->value => $this->beginGroupBooking($telegramUpdate, $session, $authorization),
            ScheduleKind::PrivateLesson->value => $this->beginPrivateBooking($telegramUpdate, $session, $authorization),
            default => $this->showBookingTypes($telegramUpdate, $session, $authorization, true),
        };
    }

    /**
     * @param  Collection<int, ScheduledClass>|null  $classes
     */
    private function beginGroupBooking(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        ?Collection $classes = null,
        bool $forceLocationChoice = false,
    ): bool {
        $classes ??= $this->eligibleClasses($authorization->account, $authorization->customer);
        $locationIds = $classes->pluck('location_id')->unique()->values();

        if ($locationIds->isEmpty()) {
            $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_no_available_classes'));

            return true;
        }

        if (! $forceLocationChoice && $locationIds->count() === 1) {
            return $this->showBookingDates($telegramUpdate, $session, $authorization, (int) $locationIds->first(), 0);
        }

        $locations = $authorization->account->locations()->active()->whereIn('id', $locationIds)->orderBy('name')->get();
        $rows = $locations->map(fn (Location $location): array => [[
            'text' => $location->name,
            'action' => 'book_location',
            'value' => $location->id,
        ]])->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'booking_types',
        ]];
        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::ChoosingLocation, $rows);
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_choose_location'), $markup, $authorization);

        return true;
    }

    private function beginPrivateBooking(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, bool $forceChoice = false): bool
    {
        if (! $this->privateLessonOptions->isConfigured($authorization->account)) {
            return $this->showBookingTypes($telegramUpdate, $session, $authorization, true);
        }

        $locations = $this->privateLessonOptions->locations($authorization->account);

        if (! $forceChoice && $locations->count() === 1) {
            return $this->showPrivateDirections($telegramUpdate, $session, $authorization, (int) $locations->first()->id);
        }

        $rows = $locations->map(fn (Location $location): array => [[
            'text' => $location->name,
            'action' => 'private_location',
            'value' => $location->id,
        ]])->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'booking_types',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateLocation,
            $rows,
            ['booking_type' => ScheduleKind::PrivateLesson->value],
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_choose_private_location'), $markup, $authorization);

        return true;
    }

    private function showPrivateDirections(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        int $locationId,
        bool $autoSelect = true,
    ): bool {
        $location = $this->privateLessonOptions->locations($authorization->account)->firstWhere('id', $locationId);

        if (! $location instanceof Location) {
            return $this->beginPrivateBooking($telegramUpdate, $session, $authorization, true);
        }

        $directions = $this->privateLessonOptions->directions($authorization->account);

        if ($directions->isEmpty()) {
            return $this->showPrivateServices($telegramUpdate, $session, $authorization, $location->id, null);
        }

        if ($autoSelect && $directions->count() === 1) {
            return $this->showPrivateServices($telegramUpdate, $session, $authorization, $location->id, (int) $directions->first()->id);
        }

        $rows = $directions->map(fn (ActivityDirection $direction): array => [[
            'text' => $direction->name,
            'action' => 'private_direction',
            'value' => $direction->id,
        ]])->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'private_locations',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateDirection,
            $rows,
            $this->privateContext(['location_id' => $location->id]),
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_choose_private_direction'), $markup, $authorization);

        return true;
    }

    private function showPrivateServices(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        int $locationId,
        ?int $activityDirectionId,
        bool $autoSelect = true,
    ): bool {
        $location = $this->privateLessonOptions->locations($authorization->account)->firstWhere('id', $locationId);
        $directions = $this->privateLessonOptions->directions($authorization->account);
        $direction = $activityDirectionId ? $directions->firstWhere('id', $activityDirectionId) : null;

        if (! $location instanceof Location) {
            return $this->beginPrivateBooking($telegramUpdate, $session, $authorization, true);
        }

        if ($directions->isNotEmpty() && ! $direction) {
            return $this->showPrivateDirections($telegramUpdate, $session, $authorization, $location->id, false);
        }

        $classTypes = $this->privateLessonOptions->classTypes($authorization->account, $direction?->id);

        if ($autoSelect && $classTypes->count() === 1) {
            return $this->showPrivateTrainers(
                $telegramUpdate,
                $session,
                $authorization,
                $location->id,
                $direction?->id,
                (int) $classTypes->first()->id,
            );
        }

        $rows = $classTypes->map(fn (ClassType $classType): array => [[
            'text' => $classType->name,
            'action' => 'private_service',
            'value' => $classType->id,
        ]])->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => $directions->isEmpty() ? 'private_locations' : 'private_directions',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateService,
            $rows,
            $this->privateContext([
                'location_id' => $location->id,
                'direction_id' => $direction?->id,
            ]),
        );
        $text = $classTypes->isEmpty()
            ? $this->t($session, 'telegram_customer_no_private_services')
            : $this->t($session, 'telegram_customer_choose_private_service');
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function showPrivateTrainers(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        int $locationId,
        ?int $activityDirectionId,
        int $classTypeId,
        bool $autoSelect = true,
    ): bool {
        $location = $this->privateLessonOptions->locations($authorization->account)->firstWhere('id', $locationId);
        $classType = $this->privateLessonOptions->classTypes($authorization->account, $activityDirectionId)->firstWhere('id', $classTypeId);

        if (! $location instanceof Location || ! $classType) {
            return $this->showPrivateServices($telegramUpdate, $session, $authorization, $locationId, $activityDirectionId, false);
        }

        $trainers = $this->privateLessonOptions->trainers($authorization->account, $location, $classType, $activityDirectionId);

        if ($autoSelect && $trainers->count() === 1) {
            return $this->continuePrivateAfterTrainer(
                $telegramUpdate,
                $session,
                $authorization,
                $location->id,
                $activityDirectionId,
                $classType->id,
                (int) $trainers->first()->id,
            );
        }

        $rows = $trainers->map(fn (Trainer $trainer): array => [[
            'text' => $trainer->name,
            'action' => 'private_trainer',
            'value' => $trainer->id,
        ]])->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'private_services',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateTrainer,
            $rows,
            $this->privateContext([
                'location_id' => $location->id,
                'direction_id' => $activityDirectionId,
                'class_type_id' => $classType->id,
            ]),
        );
        $text = $trainers->isEmpty()
            ? $this->t($session, 'telegram_customer_no_private_trainers')
            : $this->t($session, 'telegram_customer_choose_private_trainer');
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function continuePrivateAfterTrainer(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        int $locationId,
        ?int $activityDirectionId,
        int $classTypeId,
        int $trainerId,
    ): bool {
        $selection = [
            'location_id' => $locationId,
            'direction_id' => $activityDirectionId,
            'class_type_id' => $classTypeId,
            'trainer_id' => $trainerId,
            'room_id' => null,
        ];
        $resolved = $this->resolvePrivateSelection($authorization, $selection, false);

        if (! $resolved) {
            return $this->showPrivateTrainers($telegramUpdate, $session, $authorization, $locationId, $activityDirectionId, $classTypeId, false);
        }

        if ($this->privateLessonOptions->usesTrainerTimeframes($authorization->account)) {
            return $this->showPrivateDates($telegramUpdate, $session, $authorization, $selection, 0);
        }

        return $this->showPrivateRooms($telegramUpdate, $session, $authorization, $selection);
    }

    /**
     * @param  array{location_id: int, direction_id: ?int, class_type_id: int, trainer_id: int, room_id: ?int}  $selection
     */
    private function showPrivateRooms(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        array $selection,
        bool $autoSelect = true,
    ): bool {
        $resolved = $this->resolvePrivateSelection($authorization, $selection, false);

        if (! $resolved) {
            return $this->beginPrivateBooking($telegramUpdate, $session, $authorization, true);
        }

        $rooms = $this->privateLessonOptions->rooms(
            $authorization->account,
            $resolved['location'],
            $resolved['class_type'],
            $selection['direction_id'],
        );

        if ($autoSelect && $rooms->count() === 1) {
            return $this->showPrivateDates(
                $telegramUpdate,
                $session,
                $authorization,
                [...$selection, 'room_id' => (int) $rooms->first()->id],
                0,
            );
        }

        $rows = $rooms->map(fn (Room $room): array => [[
            'text' => $room->name,
            'action' => 'private_room',
            'value' => $room->id,
        ]])->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'private_trainers',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateRoom,
            $rows,
            $this->privateContext($selection),
        );
        $text = $rooms->isEmpty()
            ? $this->t($session, 'telegram_customer_no_private_rooms')
            : $this->t($session, 'telegram_customer_choose_private_room');
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function selectPrivateRoom(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $roomId): bool
    {
        $selection = [...$this->privateSelectionFromSession($session), 'room_id' => $roomId];

        return $this->showPrivateDates($telegramUpdate, $session, $authorization, $selection, 0);
    }

    /**
     * @param  array{location_id: int, direction_id: ?int, class_type_id: int, trainer_id: int, room_id: ?int}  $selection
     */
    private function showPrivateDates(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        array $selection,
        int $page,
    ): bool {
        $usesTrainerTimeframes = $this->privateLessonOptions->usesTrainerTimeframes($authorization->account);
        $resolved = $this->resolvePrivateSelection($authorization, $selection, ! $usesTrainerTimeframes);

        if (! $resolved) {
            return $this->beginPrivateBooking($telegramUpdate, $session, $authorization, true);
        }

        $lastPage = max(0, (int) ceil(self::ScheduleDays / self::DatePageSize) - 1);
        $page = max(0, min($lastPage, $page));
        $timezone = $resolved['location']->timezone ?? $authorization->account->timezone ?? config('app.timezone');
        $pageStart = Carbon::now($timezone)->startOfDay()->addDays($page * self::DatePageSize);
        $candidateDates = collect(range(0, self::DatePageSize - 1))
            ->map(fn (int $offset): Carbon => $pageStart->copy()->addDays($offset))
            ->filter(fn (Carbon $date): bool => $date->diffInDays(Carbon::now($timezone)->startOfDay()) < self::ScheduleDays)
            ->values();
        $availableDates = $this->privateLessonOptions->candidateDates(
            $authorization->account,
            $resolved['location'],
            $resolved['class_type'],
            $resolved['trainer'],
            $candidateDates,
        );
        $rows = $availableDates
            ->map(fn (Carbon $date): array => [[
                'text' => $date->locale($session->locale)->translatedFormat('D, d.m'),
                'action' => 'private_date',
                'value' => $date->toDateString(),
            ]])
            ->all();
        $pagination = [];

        if ($page > 0) {
            $pagination[] = ['text' => '←', 'action' => 'private_dates_page', 'value' => $page - 1];
        }

        if ($page < $lastPage) {
            $pagination[] = ['text' => '→', 'action' => 'private_dates_page', 'value' => $page + 1];
        }

        if ($pagination !== []) {
            $rows[] = $pagination;
        }

        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => $usesTrainerTimeframes ? 'private_trainers' : 'private_rooms',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateDate,
            $rows,
            $this->privateContext($selection, ['private_date_page' => $page]),
        );
        $text = $availableDates->isEmpty()
            ? $this->t($session, 'telegram_customer_no_private_slots_week')
            : $this->t($session, 'telegram_customer_choose_private_date', [
                'service' => $resolved['class_type']->name,
                'trainer' => $resolved['trainer']->name,
            ]);
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    /**
     * @param  array{location_id: int, direction_id: ?int, class_type_id: int, trainer_id: int, room_id: ?int}  $selection
     */
    private function showPrivateTimes(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        array $selection,
        string $date,
        string $prefix = '',
    ): bool {
        $usesTrainerTimeframes = $this->privateLessonOptions->usesTrainerTimeframes($authorization->account);
        $resolved = $this->resolvePrivateSelection($authorization, $selection, ! $usesTrainerTimeframes);

        if (! $resolved || ! $this->privateDateIsInRange($authorization, $resolved['location'], $date)) {
            return $this->showPrivateDates($telegramUpdate, $session, $authorization, $selection, 0);
        }

        $availability = $this->privateLessonOptions->availability(
            $authorization->account,
            $authorization->customer,
            $resolved['location'],
            $resolved['class_type'],
            $resolved['trainer'],
            $resolved['room'],
            $selection['direction_id'],
            $date,
        );
        $slots = collect($availability['slots'] ?? []);
        $rows = $slots
            ->map(fn (array $slot): array => [
                'text' => (string) $slot['label'],
                'action' => 'private_time',
                'value' => (string) $slot['starts_at'],
            ])
            ->chunk(2)
            ->map(fn (Collection $row): array => $row->values()->all())
            ->values()
            ->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'private_dates',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateTime,
            $rows,
            $this->privateContext($selection, [
                'private_date' => $date,
                'private_date_page' => (int) data_get($session->encrypted_context, 'private_date_page', 0),
            ]),
        );
        $text = $slots->isEmpty()
            ? $this->t($session, 'telegram_customer_no_private_times')
            : $this->t($session, 'telegram_customer_choose_private_time', ['date' => Carbon::parse($date)->locale($session->locale)->translatedFormat('D, d.m.Y')]);

        if ($prefix !== '') {
            $text = $prefix."\n\n".$text;
        }

        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function selectPrivateTime(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, string $startsAt): bool
    {
        $selection = $this->privateSelectionFromSession($session);

        if ($this->privateLessonOptions->usesTrainerTimeframes($authorization->account)) {
            return $this->showPrivateSlotRooms($telegramUpdate, $session, $authorization, $selection, $startsAt);
        }

        return $this->showPrivateBookingConfirmation($telegramUpdate, $session, $authorization, $selection, $startsAt);
    }

    /**
     * @param  array{location_id: int, direction_id: ?int, class_type_id: int, trainer_id: int, room_id: ?int}  $selection
     */
    private function showPrivateSlotRooms(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        array $selection,
        string $startsAt,
        bool $autoSelect = true,
    ): bool {
        $resolved = $this->resolvePrivateSelection($authorization, $selection, false);
        $date = substr($startsAt, 0, 10);

        if (! $resolved || ! $this->privateDateIsInRange($authorization, $resolved['location'], $date)) {
            return $this->showPrivateDates($telegramUpdate, $session, $authorization, $selection, 0);
        }

        $availability = $this->privateLessonOptions->availability(
            $authorization->account,
            $authorization->customer,
            $resolved['location'],
            $resolved['class_type'],
            $resolved['trainer'],
            null,
            $selection['direction_id'],
            $date,
        );
        $slot = collect($availability['slots'] ?? [])->first(fn (array $slot): bool => (string) $slot['starts_at'] === $startsAt);

        if (! is_array($slot)) {
            return $this->showPrivateTimes($telegramUpdate, $session, $authorization, $selection, $date, $this->t($session, 'telegram_customer_private_slot_unavailable'));
        }

        $freeRoomIds = collect($slot['rooms'] ?? [])->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $rooms = $this->privateLessonOptions
            ->rooms($authorization->account, $resolved['location'], $resolved['class_type'], $selection['direction_id'])
            ->filter(fn (Room $room): bool => $freeRoomIds->contains($room->id))
            ->values();

        if ($autoSelect && $rooms->count() === 1) {
            return $this->showPrivateBookingConfirmation(
                $telegramUpdate,
                $session,
                $authorization,
                [...$selection, 'room_id' => (int) $rooms->first()->id],
                $startsAt,
            );
        }

        if ($rooms->isEmpty()) {
            return $this->showPrivateTimes($telegramUpdate, $session, $authorization, $selection, $date, $this->t($session, 'telegram_customer_private_slot_unavailable'));
        }

        $rows = $rooms->map(fn (Room $room): array => [[
            'text' => $room->name,
            'action' => 'private_slot_room',
            'value' => $room->id,
        ]])->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'private_times',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingPrivateRoom,
            $rows,
            $this->privateContext($selection, [
                'private_date' => $date,
                'private_starts_at' => $startsAt,
                'private_date_page' => (int) data_get($session->encrypted_context, 'private_date_page', 0),
            ]),
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_choose_private_slot_room'), $markup, $authorization);

        return true;
    }

    /**
     * @param  array{location_id: int, direction_id: ?int, class_type_id: int, trainer_id: int, room_id: ?int}  $selection
     */
    private function showPrivateBookingConfirmation(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        array $selection,
        string $startsAt,
    ): bool {
        $resolved = $this->resolvePrivateSelection($authorization, $selection, true);
        $date = substr($startsAt, 0, 10);

        if (! $resolved || ! $this->privateDateIsInRange($authorization, $resolved['location'], $date)) {
            return $this->beginPrivateBooking($telegramUpdate, $session, $authorization, true);
        }

        $availability = $this->privateLessonOptions->availability(
            $authorization->account,
            $authorization->customer,
            $resolved['location'],
            $resolved['class_type'],
            $resolved['trainer'],
            $resolved['room'],
            $selection['direction_id'],
            $date,
        );
        $slot = collect($availability['slots'] ?? [])->first(fn (array $slot): bool => (string) $slot['starts_at'] === $startsAt);

        if (! is_array($slot)) {
            return $this->showPrivateTimes($telegramUpdate, $session, $authorization, $selection, $date, $this->t($session, 'telegram_customer_private_slot_unavailable'));
        }

        if ($this->privateLessonOptions->usesTrainerTimeframes($authorization->account)) {
            $selectedRoomAvailable = collect($slot['rooms'] ?? [])->contains(fn (array $room): bool => (int) $room['id'] === $resolved['room']->id);

            if (! $selectedRoomAvailable) {
                return $this->showPrivateSlotRooms($telegramUpdate, $session, $authorization, [...$selection, 'room_id' => null], $startsAt, false);
            }
        }

        $previewClass = $this->privateLessonOptions->previewClass(
            $authorization->account,
            $resolved['location'],
            $resolved['class_type'],
            $resolved['trainer'],
            $resolved['room'],
            $startsAt,
        );
        $pass = $this->suitablePass($authorization->customer, $previewClass);
        $localStartsAt = $previewClass->starts_at->copy()->timezone($previewClass->displayTimezone());
        $cancellationCloses = $this->cancellationWindow->closesAt($previewClass)?->timezone($previewClass->displayTimezone())->format('d.m H:i') ?? '—';
        $passText = $pass
            ? $this->t($session, 'telegram_customer_booking_pass', ['pass' => $pass->plan_name, 'code' => $pass->code])
            : $this->t($session, 'telegram_customer_booking_without_pass_warning');
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ConfirmingPrivateBooking,
            [[
                ['text' => $this->t($session, 'telegram_customer_confirm_booking_button'), 'action' => 'confirm_private_booking'],
                ['text' => $this->t($session, 'back'), 'action' => 'private_times'],
            ]],
            $this->privateContext($selection, [
                'private_date' => $date,
                'private_starts_at' => $startsAt,
                'private_date_page' => (int) data_get($session->encrypted_context, 'private_date_page', 0),
            ]),
            self::ConfirmationMinutes,
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_private_booking_confirmation', [
            'service' => $resolved['class_type']->name,
            'date' => $localStartsAt->locale($session->locale)->translatedFormat('D, d.m.Y'),
            'time' => (string) $slot['label'],
            'duration' => (int) ($resolved['class_type']->default_duration_minutes ?: 60),
            'trainer' => $resolved['trainer']->name,
            'location' => $resolved['location']->name,
            'room' => $resolved['room']->name,
            'cancellation_cutoff' => $cancellationCloses,
            'pass' => $passText,
        ]), $markup, $authorization);

        return true;
    }

    private function confirmPrivateBooking(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization): bool
    {
        $selection = $this->privateSelectionFromSession($session);
        $startsAt = (string) data_get($session->encrypted_context, 'private_starts_at');
        $date = substr($startsAt, 0, 10);
        $resolved = $this->resolvePrivateSelection($authorization, $selection, true);

        if (! $resolved || ! $this->privateDateIsInRange($authorization, $resolved['location'], $date)) {
            return $this->beginPrivateBooking($telegramUpdate, $session, $authorization, true);
        }

        try {
            $booking = $this->createPublicBooking->execute(
                $authorization->account,
                $resolved['location'],
                $authorization->customer,
                [
                    'schedule_kind' => ScheduleKind::PrivateLesson->value,
                    'date' => $date,
                    'starts_at' => $startsAt,
                    'class_type_id' => $resolved['class_type']->id,
                    'activity_direction_id' => $selection['direction_id'],
                    'room_id' => $resolved['room']->id,
                    'trainer_id' => $resolved['trainer']->id,
                    'people_count' => 1,
                    'notes' => null,
                ],
            );
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?: $this->t($session, 'telegram_customer_private_slot_unavailable');

            return $this->showPrivateTimes($telegramUpdate, $session, $authorization, [...$selection, 'room_id' => $this->privateLessonOptions->usesTrainerTimeframes($authorization->account) ? null : $selection['room_id']], $date, $message);
        }

        return $this->sendBookingCreated($telegramUpdate, $session, $authorization, $booking);
    }

    private function showBookingDates(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $locationId, int $page): bool
    {
        $location = $authorization->account->locations()->active()->whereKey($locationId)->first();

        if (! $location) {
            return $this->beginBooking($telegramUpdate, $session, $authorization);
        }

        $eligibleClasses = $this->eligibleClasses($authorization->account, $authorization->customer, $locationId);
        $dates = $eligibleClasses
            ->map(fn (ScheduledClass $class): string => $class->starts_at->copy()->timezone($class->displayTimezone())->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_no_available_classes'));

            return true;
        }

        $lastPage = max(0, (int) ceil($dates->count() / self::DatePageSize) - 1);
        $page = max(0, min($lastPage, $page));
        $rows = $dates->slice($page * self::DatePageSize, self::DatePageSize)
            ->map(function (string $date) use ($session): array {
                $day = Carbon::parse($date);

                return [[
                    'text' => $day->locale($session->locale)->translatedFormat('D, d.m'),
                    'action' => 'book_date',
                    'value' => $date,
                ]];
            })->values()->all();
        $pagination = [];

        if ($page > 0) {
            $pagination[] = ['text' => '←', 'action' => 'book_dates_page', 'value' => $page - 1];
        }

        if ($page < $lastPage) {
            $pagination[] = ['text' => '→', 'action' => 'book_dates_page', 'value' => $page + 1];
        }

        if ($pagination !== []) {
            $rows[] = $pagination;
        }

        $availableLocationCount = $this->eligibleClasses($authorization->account, $authorization->customer)
            ->pluck('location_id')
            ->unique()
            ->count();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => $availableLocationCount > 1 ? 'book_locations' : 'booking_types',
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingDate,
            $rows,
            ['location_id' => $location->id, 'date_page' => $page],
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_choose_date', ['location' => $location->name]), $markup, $authorization);

        return true;
    }

    private function showClassesForDate(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, string $date): bool
    {
        $locationId = (int) data_get($session->encrypted_context, 'location_id');
        $datePage = (int) data_get($session->encrypted_context, 'date_page', 0);
        $classes = $this->eligibleClasses($authorization->account, $authorization->customer, $locationId)
            ->filter(fn (ScheduledClass $class): bool => $class->starts_at->copy()->timezone($class->displayTimezone())->toDateString() === $date)
            ->values();

        if ($classes->isEmpty()) {
            return $this->showBookingDates($telegramUpdate, $session, $authorization, $locationId, 0);
        }

        $rows = $classes->map(function (ScheduledClass $class): array {
            $startsAt = $class->starts_at->copy()->timezone($class->displayTimezone());

            return [[
                'text' => $startsAt->format('H:i').' · '.$class->displayTitle().' · '.$this->availableSpots($class),
                'action' => 'book_class',
                'value' => $class->id,
            ]];
        })->all();
        $rows[] = [[
            'text' => $this->t($session, 'back'),
            'action' => 'book_dates_page',
            'value' => $datePage,
        ]];
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ChoosingClass,
            $rows,
            ['location_id' => $locationId, 'date' => $date, 'date_page' => $datePage],
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_choose_class'), $markup, $authorization);

        return true;
    }

    private function showBookingConfirmation(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $scheduledClassId): bool
    {
        $class = $this->classForCustomer($authorization, $scheduledClassId);

        if (! $class || ! $this->classIsAvailableToCustomer($class, $authorization->customer)) {
            $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_class_no_longer_available'));

            return true;
        }

        $pass = $this->suitablePass($authorization->customer, $class);
        $startsAt = $class->starts_at->copy()->timezone($class->displayTimezone());
        $datePage = (int) data_get($session->encrypted_context, 'date_page', 0);
        $bookingCloses = $class->bookingClosesAt()?->timezone($class->displayTimezone())->format('d.m H:i') ?? '—';
        $cancellationCloses = $this->cancellationWindow->closesAt($class)?->timezone($class->displayTimezone())->format('d.m H:i') ?? '—';
        $passText = $pass
            ? $this->t($session, 'telegram_customer_booking_pass', ['pass' => $pass->plan_name, 'code' => $pass->code])
            : $this->t($session, 'telegram_customer_booking_without_pass_warning');
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ConfirmingBooking,
            [[
                ['text' => $this->t($session, 'telegram_customer_confirm_booking_button'), 'action' => 'confirm_booking', 'value' => $class->id],
                ['text' => $this->t($session, 'back'), 'action' => 'book_date', 'value' => $startsAt->toDateString()],
            ]],
            ['location_id' => $class->location_id, 'date' => $startsAt->toDateString(), 'date_page' => $datePage],
            self::ConfirmationMinutes,
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_booking_confirmation', [
            'class' => $class->displayTitle(),
            'date' => $startsAt->locale($session->locale)->translatedFormat('D, d.m.Y'),
            'time' => $startsAt->format('H:i'),
            'trainer' => $class->trainer?->name ?: '—',
            'location' => $class->location?->name ?: '—',
            'room' => $class->room?->name ?: '—',
            'spots' => $this->availableSpots($class),
            'booking_cutoff' => $bookingCloses,
            'cancellation_cutoff' => $cancellationCloses,
            'pass' => $passText,
        ]), $markup, $authorization);

        return true;
    }

    private function confirmBooking(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $scheduledClassId): bool
    {
        $class = $this->classForCustomer($authorization, $scheduledClassId);

        if (! $class) {
            $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_class_no_longer_available'));

            return true;
        }

        try {
            $booking = $this->createPublicBooking->execute(
                $authorization->account,
                $class->location,
                $authorization->customer,
                [
                    'schedule_kind' => ScheduleKind::GroupClass->value,
                    'scheduled_class_id' => $class->id,
                    'notes' => null,
                ],
            );
        } catch (ValidationException $exception) {
            $this->showMainMenu($telegramUpdate, $session, $authorization, collect($exception->errors())->flatten()->first() ?: $this->t($session, 'telegram_customer_class_no_longer_available'));

            return true;
        }

        return $this->sendBookingCreated($telegramUpdate, $session, $authorization, $booking);
    }

    private function sendBookingCreated(
        TelegramUpdate $telegramUpdate,
        TelegramCustomerSession $session,
        TelegramChatAuthorization $authorization,
        ClassBooking $booking,
    ): bool {
        $booking->load([
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.classType',
            'scheduledClass.trainer',
            'classPassReservation.customerClassPass',
        ]);
        $reservedPass = $booking->classPassReservation?->customerClassPass;
        $text = $this->t($session, 'telegram_customer_booking_created', [
            'class' => $booking->scheduledClass->displayTitle(),
            'date' => $booking->scheduledClass->starts_at->copy()->timezone($booking->scheduledClass->displayTimezone())->format('d.m.Y H:i'),
        ]);
        $text .= "\n\n".($reservedPass
            ? $this->t($session, 'telegram_customer_booking_reserved_pass', ['pass' => $reservedPass->plan_name, 'code' => $reservedPass->code])
            : $this->t($session, 'telegram_customer_booking_created_without_pass'));
        $buttons = [[
            ['text' => $this->t($session, 'telegram_customer_my_bookings_button'), 'action' => 'booking_detail', 'value' => $booking->id],
            ['text' => $this->t($session, 'telegram_customer_menu_button'), 'action' => 'menu'],
        ]];
        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::Idle, $buttons, [], self::SessionMinutes);
        $markup['reply_markup']['inline_keyboard'][] = [[
            'text' => $this->t($session, 'telegram_customer_add_to_calendar'),
            'url' => $this->calendarUrl($booking->scheduledClass),
        ]];

        if (! $reservedPass && $booking->scheduledClass->location) {
            $markup['reply_markup']['inline_keyboard'][] = [[
                'text' => $this->t($session, 'telegram_customer_buy_pass'),
                'url' => route('public.price', [$authorization->account->slug, $booking->scheduledClass->location->slug]),
            ]];
        }

        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function showBookings(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization): bool
    {
        $bookings = $authorization->customer->classBookings()
            ->notCorrectedRemoved()
            ->where('account_id', $authorization->account_id)
            ->where('status', ClassBookingStatus::Booked->value)
            ->whereHas('scheduledClass', fn (Builder $query): Builder => $query->where('starts_at', '>', now()))
            ->with(['scheduledClass.location', 'scheduledClass.room', 'scheduledClass.classType', 'scheduledClass.trainer'])
            ->orderBy(ScheduledClass::select('starts_at')->whereColumn('scheduled_classes.id', 'class_bookings.scheduled_class_id'))
            ->limit(10)
            ->get();

        if ($bookings->isEmpty()) {
            $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::ChoosingBooking, [[
                ['text' => $this->t($session, 'telegram_customer_booking_history_button'), 'action' => 'booking_history', 'value' => 0],
                ['text' => $this->t($session, 'telegram_customer_menu_button'), 'action' => 'menu'],
            ]]);
            $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_no_upcoming_bookings'), $markup, $authorization);

            return true;
        }

        $rows = $bookings->map(function (ClassBooking $booking): array {
            $class = $booking->scheduledClass;
            $startsAt = $class->starts_at->copy()->timezone($class->displayTimezone());

            return [[
                'text' => $startsAt->format('d.m H:i').' · '.$class->displayTitle(),
                'action' => 'booking_detail',
                'value' => $booking->id,
            ]];
        })->all();
        $rows[] = [['text' => $this->t($session, 'telegram_customer_booking_history_button'), 'action' => 'booking_history', 'value' => 0]];
        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::ChoosingBooking, $rows);
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_upcoming_bookings'), $markup, $authorization);

        return true;
    }

    private function showBookingDetail(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $bookingId): bool
    {
        $booking = $authorization->customer->classBookings()
            ->notCorrectedRemoved()
            ->where('account_id', $authorization->account_id)
            ->whereKey($bookingId)
            ->with(['scheduledClass.location', 'scheduledClass.room', 'scheduledClass.classType', 'scheduledClass.trainer', 'classPassReservation.customerClassPass'])
            ->first();

        if (! $booking || ! $booking->scheduledClass) {
            return $this->showBookings($telegramUpdate, $session, $authorization);
        }

        $class = $booking->scheduledClass;
        $startsAt = $class->starts_at->copy()->timezone($class->displayTimezone());
        $pass = $booking->classPassReservation?->customerClassPass;
        $rows = [[['text' => $this->t($session, 'telegram_customer_my_bookings_button'), 'action' => 'bookings']]];

        if ($booking->status === ClassBookingStatus::Booked && $class->starts_at->isFuture() && ! $this->cancellationWindow->isLockedForBooking($booking)) {
            array_unshift($rows, [[
                'text' => $this->t($session, 'telegram_customer_cancel_booking_button'),
                'action' => 'confirm_cancel_booking',
                'value' => $booking->id,
            ]]);
        }

        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::ChoosingBooking, $rows);
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_booking_detail', [
            'class' => $class->displayTitle(),
            'date' => $startsAt->format('d.m.Y H:i'),
            'location' => $class->location?->name ?: '—',
            'room' => $class->room?->name ?: '—',
            'trainer' => $class->trainer?->name ?: '—',
            'status' => $this->t($session, 'telegram_customer_booking_status_'.$booking->status->value),
            'pass' => $pass ? $pass->plan_name.' · '.$pass->code : $this->t($session, 'telegram_customer_no_pass'),
        ]), $markup, $authorization);

        return true;
    }

    private function showCancellationConfirmation(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $bookingId): bool
    {
        $booking = $authorization->customer->classBookings()
            ->notCorrectedRemoved()
            ->where('account_id', $authorization->account_id)
            ->whereKey($bookingId)
            ->with('scheduledClass.classType')
            ->first();

        if (! $booking || $booking->status !== ClassBookingStatus::Booked || ! $booking->scheduledClass?->starts_at?->isFuture() || $this->cancellationWindow->isLockedForBooking($booking)) {
            return $this->showBookingDetail($telegramUpdate, $session, $authorization, $bookingId);
        }

        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ConfirmingCancellation,
            [[
                ['text' => $this->t($session, 'telegram_customer_confirm_cancel_button'), 'action' => 'cancel_booking', 'value' => $booking->id],
                ['text' => $this->t($session, 'back'), 'action' => 'booking_detail', 'value' => $booking->id],
            ]],
            [],
            self::ConfirmationMinutes,
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_confirm_cancel_booking', ['class' => $booking->scheduledClass->displayTitle()]), $markup, $authorization);

        return true;
    }

    private function confirmCancellation(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $bookingId): bool
    {
        $booking = $authorization->customer->classBookings()
            ->notCorrectedRemoved()
            ->where('account_id', $authorization->account_id)
            ->whereKey($bookingId)
            ->with('scheduledClass.classType')
            ->first();

        if (! $booking) {
            return $this->showBookings($telegramUpdate, $session, $authorization);
        }

        try {
            $this->cancelClassBooking->execute($booking, requireBookedUpcoming: true);
        } catch (ValidationException $exception) {
            $this->showMainMenu($telegramUpdate, $session, $authorization, collect($exception->errors())->flatten()->first() ?: $this->t($session, 'telegram_customer_cancel_unavailable'));

            return true;
        }

        $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_booking_cancelled'));

        return true;
    }

    private function showBookingHistory(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $page): bool
    {
        $query = ClassBooking::query()
            ->whereBelongsTo($authorization->customer)
            ->whereBelongsTo($authorization->account)
            ->notCorrectedRemoved()
            ->where(function (Builder $query): void {
                $query->where('status', ClassBookingStatus::Cancelled->value)
                    ->orWhereHas('scheduledClass', fn (Builder $query): Builder => $query->where('starts_at', '<=', now()));
            });

        return $this->showBookingPage($telegramUpdate, $session, $authorization, $query, $page);
    }

    private function showPasses(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $page, bool $history): bool
    {
        $query = $authorization->customer->customerClassPasses()
            ->where('account_id', $authorization->account_id)
            ->when(! $history, fn (Builder $query): Builder => $query->where('is_active', true))
            ->orderByDesc('is_active')
            ->latest('purchased_at')
            ->latest('id');
        $total = $query->count();
        $lastPage = max(0, (int) ceil($total / self::ListPageSize) - 1);
        $page = max(0, min($lastPage, $page));
        $passes = $query->skip($page * self::ListPageSize)->take(self::ListPageSize)->get();

        if ($passes->isEmpty()) {
            if (! $history) {
                return $this->showPasses($telegramUpdate, $session, $authorization, 0, true);
            }

            $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_no_passes'));

            return true;
        }

        $text = $passes->map(fn (CustomerClassPass $pass): string => $this->t($session, 'telegram_customer_pass_line', [
            'name' => $pass->plan_name,
            'code' => $pass->code,
            'status' => $this->t($session, 'telegram_customer_pass_status_'.$pass->status->value),
            'payment' => $this->t($session, 'telegram_customer_payment_'.$pass->paymentStatus()),
            'remaining' => $pass->remainingSessionsCount(),
            'reserved' => $pass->reserved_sessions_count,
            'used' => $pass->used_sessions_count,
            'total' => $pass->sessions_count,
            'until' => $pass->usableUntilAt()?->timezone($authorization->account->timezone)->format('d.m.Y') ?? '—',
        ]))->implode("\n\n");
        $rows = [];
        $pagination = [];

        if ($page > 0) {
            $pagination[] = ['text' => '←', 'action' => 'passes_page', 'value' => ['page' => $page - 1, 'history' => $history]];
        }

        if ($page < $lastPage) {
            $pagination[] = ['text' => '→', 'action' => 'passes_page', 'value' => ['page' => $page + 1, 'history' => $history]];
        }

        if ($pagination !== []) {
            $rows[] = $pagination;
        }

        if (! $history) {
            $rows[] = [['text' => $this->t($session, 'telegram_customer_pass_history_button'), 'action' => 'passes_page', 'value' => ['page' => 0, 'history' => true]]];
        }

        $rows[] = [['text' => $this->t($session, 'telegram_customer_menu_button'), 'action' => 'menu']];
        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::Idle, $rows);
        $location = $authorization->account->locations()->active()->orderBy('name')->first();

        if ($location) {
            $markup['reply_markup']['inline_keyboard'][] = [[
                'text' => $this->t($session, 'telegram_customer_buy_pass'),
                'url' => route('public.price', [$authorization->account->slug, $location->slug]),
            ]];
        }

        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function showAttendance(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, int $page): bool
    {
        $query = $authorization->customer->classBookings()
            ->notCorrectedRemoved()
            ->where('account_id', $authorization->account_id)
            ->whereIn('status', [ClassBookingStatus::Attended->value, ClassBookingStatus::NoShow->value])
            ->with('scheduledClass.location')
            ->latest('id');
        $total = $query->count();
        $lastPage = max(0, (int) ceil($total / self::ListPageSize) - 1);
        $page = max(0, min($lastPage, $page));
        $bookings = $query->skip($page * self::ListPageSize)->take(self::ListPageSize)->get();

        if ($bookings->isEmpty()) {
            $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_no_attendance'));

            return true;
        }

        $text = $bookings->map(function (ClassBooking $booking) use ($session): string {
            $class = $booking->scheduledClass;
            $startsAt = $class->starts_at->copy()->timezone($class->displayTimezone());

            return $this->t($session, 'telegram_customer_attendance_line', [
                'date' => $startsAt->format('d.m.Y'),
                'class' => $class->displayTitle(),
                'status' => $this->t($session, 'telegram_customer_booking_status_'.$booking->status->value),
            ]);
        })->implode("\n");
        $rows = [];
        $pagination = [];

        if ($page > 0) {
            $pagination[] = ['text' => '←', 'action' => 'attendance_page', 'value' => $page - 1];
        }

        if ($page < $lastPage) {
            $pagination[] = ['text' => '→', 'action' => 'attendance_page', 'value' => $page + 1];
        }

        if ($pagination !== []) {
            $rows[] = $pagination;
        }

        $rows[] = [['text' => $this->t($session, 'telegram_customer_menu_button'), 'action' => 'menu']];
        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::Idle, $rows);
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function showStudio(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization): bool
    {
        $account = $authorization->account;
        $locations = $account->locations()->active()->orderBy('name')->get();
        $locationText = $locations->map(fn (Location $location): string => '• '.$location->name
            .($location->address ? ' — '.$location->address : '')
            .($location->phone ? ' — '.$location->phone : ''))->implode("\n");
        $text = $account->name;

        if ($account->studio_slogan) {
            $text .= "\n".$account->studio_slogan;
        }

        if ($locationText !== '') {
            $text .= "\n\n".$locationText;
        }

        $rows = [[[
            'text' => $this->t($session, 'telegram_customer_open_cabinet'),
            'url' => $this->customerLoginTokens->issueUrl($account, $authorization->customer, $authorization),
        ]]];

        if ($account->studio_rules_html) {
            $rows[] = [['text' => $this->t($session, 'studio_rules'), 'url' => route('public.studio-rules', $account->slug)]];
        }

        if ($account->public_offer_html) {
            $rows[] = [['text' => $this->t($session, 'public_offer'), 'url' => route('public.studio-offer', $account->slug)]];
        }

        $plainSupportLinks = [];

        foreach ($account->publicSupportLinks() as $link) {
            if (! $this->isTelegramButtonUrl($link['url'])) {
                $displayUrl = in_array($link['key'], ['phone', 'secondary_phone'], true)
                    ? Str::after($link['url'], 'tel://')
                    : $link['url'];
                $plainSupportLinks[] = __($link['label_key'], [], $session->locale).': '.$displayUrl;

                continue;
            }

            $rows[] = [['text' => __($link['label_key'], [], $session->locale), 'url' => $link['url']]];
        }

        if ($plainSupportLinks !== []) {
            $text .= "\n\n".implode("\n", $plainSupportLinks);
        }

        $callbackMarkup = $this->callbackMarkup($session, TelegramCustomerSessionState::Idle, [[
            ['text' => $this->t($session, 'telegram_customer_menu_button'), 'action' => 'menu'],
        ]]);
        $callbackMarkup['reply_markup']['inline_keyboard'] = [...$rows, ...$callbackMarkup['reply_markup']['inline_keyboard']];
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $callbackMarkup, $authorization);

        return true;
    }

    private function showSettings(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization): bool
    {
        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::ChoosingLanguage, [
            [
                ['text' => 'Українська', 'action' => 'set_language', 'value' => 'uk'],
                ['text' => 'English', 'action' => 'set_language', 'value' => 'en'],
            ],
            [
                ['text' => $this->t($session, 'telegram_customer_unlink_button'), 'action' => 'unlink'],
                ['text' => $this->t($session, 'telegram_customer_menu_button'), 'action' => 'menu'],
            ],
        ]);
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_settings'), $markup, $authorization);

        return true;
    }

    private function setLanguage(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, string $locale): bool
    {
        if (! array_key_exists($locale, config('ladna.locales', []))) {
            return $this->showSettings($telegramUpdate, $session, $authorization);
        }

        $session->forceFill(['locale' => $locale])->save();
        $authorization->customer->forceFill(['default_language' => $locale])->save();
        $this->showMainMenu($telegramUpdate, $session, $authorization, $this->t($session, 'telegram_customer_language_updated'));

        return true;
    }

    private function showUnlinkConfirmation(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization): bool
    {
        $markup = $this->callbackMarkup(
            $session,
            TelegramCustomerSessionState::ConfirmingUnlink,
            [[
                ['text' => $this->t($session, 'telegram_customer_confirm_unlink_button'), 'action' => 'confirm_unlink'],
                ['text' => $this->t($session, 'back'), 'action' => 'settings'],
            ]],
            [],
            self::ConfirmationMinutes,
        );
        $this->send($telegramUpdate, $session->telegram_chat_id, $this->t($session, 'telegram_customer_confirm_unlink'), $markup, $authorization);

        return true;
    }

    private function unlink(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization): bool
    {
        DB::transaction(function () use ($session, $authorization): void {
            $authorization->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ])->save();
            $session->forceFill([
                'telegram_chat_authorization_id' => null,
                'state' => TelegramCustomerSessionState::AwaitingContact->value,
                'encrypted_context' => null,
                'expires_at' => now()->addMinutes(self::SessionMinutes),
            ])->save();
        });
        $this->requestContact($telegramUpdate, $session, $this->t($session, 'telegram_customer_unlinked'));

        return true;
    }

    /**
     * @param  Builder<ClassBooking>  $query
     */
    private function showBookingPage(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, Builder $query, int $page): bool
    {
        $total = $query->count();
        $lastPage = max(0, (int) ceil($total / self::ListPageSize) - 1);
        $page = max(0, min($lastPage, $page));
        $bookings = $query->with('scheduledClass.location')->latest('id')->skip($page * self::ListPageSize)->take(self::ListPageSize)->get();

        if ($bookings->isEmpty()) {
            return $this->showBookings($telegramUpdate, $session, $authorization);
        }

        $text = $bookings->map(function (ClassBooking $booking) use ($session): string {
            $class = $booking->scheduledClass;
            $startsAt = $class->starts_at->copy()->timezone($class->displayTimezone());

            return $this->t($session, 'telegram_customer_booking_history_line', [
                'date' => $startsAt->format('d.m.Y H:i'),
                'class' => $class->displayTitle(),
                'status' => $this->t($session, 'telegram_customer_booking_status_'.$booking->status->value),
            ]);
        })->implode("\n");
        $rows = [];
        $pagination = [];

        if ($page > 0) {
            $pagination[] = ['text' => '←', 'action' => 'booking_history', 'value' => $page - 1];
        }

        if ($page < $lastPage) {
            $pagination[] = ['text' => '→', 'action' => 'booking_history', 'value' => $page + 1];
        }

        if ($pagination !== []) {
            $rows[] = $pagination;
        }

        $rows[] = [['text' => $this->t($session, 'telegram_customer_my_bookings_button'), 'action' => 'bookings']];
        $markup = $this->callbackMarkup($session, TelegramCustomerSessionState::ChoosingBooking, $rows);
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, $markup, $authorization);

        return true;
    }

    private function privateContextId(TelegramCustomerSession $session, string $key): ?int
    {
        $value = (int) data_get($session->encrypted_context, $key);

        return $value > 0 ? $value : null;
    }

    /**
     * @return array{location_id: int, direction_id: ?int, class_type_id: int, trainer_id: int, room_id: ?int}
     */
    private function privateSelectionFromSession(TelegramCustomerSession $session): array
    {
        return [
            'location_id' => (int) data_get($session->encrypted_context, 'private_location_id'),
            'direction_id' => $this->privateContextId($session, 'private_direction_id'),
            'class_type_id' => (int) data_get($session->encrypted_context, 'private_class_type_id'),
            'trainer_id' => (int) data_get($session->encrypted_context, 'private_trainer_id'),
            'room_id' => $this->privateContextId($session, 'private_room_id'),
        ];
    }

    /**
     * @param  array{location_id: int, direction_id?: ?int, class_type_id?: int, trainer_id?: int, room_id?: ?int}  $selection
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function privateContext(array $selection, array $extra = []): array
    {
        return [
            'booking_type' => ScheduleKind::PrivateLesson->value,
            'private_location_id' => $selection['location_id'],
            'private_direction_id' => $selection['direction_id'] ?? null,
            'private_class_type_id' => $selection['class_type_id'] ?? null,
            'private_trainer_id' => $selection['trainer_id'] ?? null,
            'private_room_id' => $selection['room_id'] ?? null,
            ...$extra,
        ];
    }

    /**
     * @param  array{location_id: int, direction_id: ?int, class_type_id: int, trainer_id: int, room_id: ?int}  $selection
     * @return array{location: Location, class_type: ClassType, trainer: Trainer, room: ?Room}|null
     */
    private function resolvePrivateSelection(TelegramChatAuthorization $authorization, array $selection, bool $requireRoom): ?array
    {
        $account = $authorization->account;
        $location = $this->privateLessonOptions->locations($account)->firstWhere('id', $selection['location_id']);

        if (! $location instanceof Location) {
            return null;
        }

        $directions = $this->privateLessonOptions->directions($account);
        $activityDirectionId = $selection['direction_id'];

        if ($directions->isNotEmpty() && ! $directions->contains('id', $activityDirectionId)) {
            return null;
        }

        if ($directions->isEmpty()) {
            $activityDirectionId = null;
        }

        $classType = $this->privateLessonOptions->classTypes($account, $activityDirectionId)->firstWhere('id', $selection['class_type_id']);

        if (! $classType instanceof ClassType) {
            return null;
        }

        $trainer = $this->privateLessonOptions->trainers($account, $location, $classType, $activityDirectionId)->firstWhere('id', $selection['trainer_id']);

        if (! $trainer instanceof Trainer) {
            return null;
        }

        $room = $selection['room_id']
            ? $this->privateLessonOptions->rooms($account, $location, $classType, $activityDirectionId)->firstWhere('id', $selection['room_id'])
            : null;

        if ($requireRoom && ! $room instanceof Room) {
            return null;
        }

        return [
            'location' => $location,
            'class_type' => $classType,
            'trainer' => $trainer,
            'room' => $room instanceof Room ? $room : null,
        ];
    }

    private function privateDateIsInRange(TelegramChatAuthorization $authorization, Location $location, string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        $timezone = $location->timezone ?? $authorization->account->timezone ?? config('app.timezone');

        try {
            $candidate = Carbon::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', $timezone);
        } catch (Throwable) {
            return false;
        }

        if (! $candidate || $candidate->toDateString() !== $date) {
            return false;
        }

        $today = Carbon::now($timezone)->startOfDay();

        return $candidate->betweenIncluded($today, $today->copy()->addDays(self::ScheduleDays - 1));
    }

    /**
     * @return Collection<int, ScheduledClass>
     */
    private function eligibleClasses(Account $account, Customer $customer, ?int $locationId = null): Collection
    {
        return $account->scheduledClasses()
            ->publicUpcoming()
            ->when($locationId, fn (Builder $query): Builder => $query->where('location_id', $locationId))
            ->where('starts_at', '<=', now()->addDays(self::ScheduleDays)->endOfDay())
            ->with(['account', 'location', 'room', 'classType', 'trainer'])
            ->withCount(['classBookings as active_bookings_count' => fn (Builder $query): Builder => $query
                ->notCorrectedRemoved()
                ->whereIn('status', [ClassBookingStatus::Booked->value, ClassBookingStatus::Attended->value])])
            ->limit(200)
            ->get()
            ->filter(fn (ScheduledClass $class): bool => $class->isBookingOpen() && $this->classIsAvailableToCustomer($class, $customer))
            ->values();
    }

    private function classForCustomer(TelegramChatAuthorization $authorization, int $scheduledClassId): ?ScheduledClass
    {
        return $authorization->account->scheduledClasses()
            ->publicUpcoming()
            ->whereKey($scheduledClassId)
            ->with(['account', 'location', 'room', 'classType', 'trainer'])
            ->withCount(['classBookings as active_bookings_count' => fn (Builder $query): Builder => $query
                ->notCorrectedRemoved()
                ->whereIn('status', [ClassBookingStatus::Booked->value, ClassBookingStatus::Attended->value])])
            ->first();
    }

    private function classIsAvailableToCustomer(ScheduledClass $class, Customer $customer): bool
    {
        $alreadyBooked = $class->classBookings()
            ->notCorrectedRemoved()
            ->whereBelongsTo($customer)
            ->whereIn('status', [ClassBookingStatus::Booked->value, ClassBookingStatus::Attended->value])
            ->exists();

        return $alreadyBooked || ($class->capacity > 0 && $this->availableSpots($class) > 0);
    }

    private function availableSpots(ScheduledClass $class): int
    {
        return max(0, (int) $class->capacity - (int) ($class->active_bookings_count ?? 0));
    }

    private function suitablePass(Customer $customer, ScheduledClass $class): ?CustomerClassPass
    {
        return $customer->customerClassPasses()
            ->active()
            ->where('account_id', $class->account_id)
            ->with('classPassPlan')
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->get()
            ->first(fn (CustomerClassPass $pass): bool => $pass->canReserveFor($class));
    }

    /**
     * @param  array{kind: string, chat_id: string, chat_type: string, telegram_user_id: string, username: string, language_code: string, text: string, message_id: string, message: array<string, mixed>, callback_id: string, callback_data: string}  $payload
     */
    private function sessionFor(TelegramUpdate $telegramUpdate, array $payload): TelegramCustomerSession
    {
        $session = TelegramCustomerSession::query()->firstOrNew([
            'telegram_bot_installation_id' => $telegramUpdate->telegram_bot_installation_id,
            'telegram_chat_id' => $payload['chat_id'],
        ]);
        $locale = $session->exists ? $session->locale : $this->localeFromTelegram($telegramUpdate->installation->account, $payload['language_code']);

        if ($session->exists && ! hash_equals($session->telegram_user_id, $payload['telegram_user_id'])) {
            $session->authorization?->forceFill([
                'status' => TelegramChatAuthorizationStatus::Revoked->value,
                'revoked_at' => now(),
            ])->save();
            $session->telegram_chat_authorization_id = null;
            $session->encrypted_context = null;
            $session->state = TelegramCustomerSessionState::AwaitingContact;
        }

        $session->fill([
            'account_id' => $telegramUpdate->installation->account_id,
            'telegram_user_id' => $payload['telegram_user_id'],
            'locale' => $locale,
            'last_interaction_at' => now(),
        ]);

        if (! $session->exists) {
            $session->state = TelegramCustomerSessionState::AwaitingContact;
        }

        if ($session->expires_at?->isPast()) {
            $session->encrypted_context = null;
            $session->state = $session->telegram_chat_authorization_id
                ? TelegramCustomerSessionState::Idle
                : TelegramCustomerSessionState::AwaitingContact;
        }

        $session->expires_at = now()->addMinutes(self::SessionMinutes);
        $session->save();

        return $session;
    }

    /**
     * @param  array{telegram_user_id: string}  $payload
     */
    private function currentAuthorization(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, array $payload): ?TelegramChatAuthorization
    {
        $authorization = TelegramChatAuthorization::query()
            ->with(['account', 'customer'])
            ->where('telegram_bot_installation_id', $telegramUpdate->telegram_bot_installation_id)
            ->where('telegram_chat_id', $session->telegram_chat_id)
            ->where('telegram_user_id', $payload['telegram_user_id'])
            ->where('profile', TelegramBotProfile::Customer->value)
            ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
            ->first();

        if (! $authorization || ! $authorization->customer || $authorization->customer->account_id !== $authorization->account_id) {
            if ($authorization) {
                $authorization->forceFill([
                    'status' => TelegramChatAuthorizationStatus::Revoked->value,
                    'revoked_at' => now(),
                ])->save();
            }

            $session->forceFill(['telegram_chat_authorization_id' => null])->save();

            return null;
        }

        if ($session->telegram_chat_authorization_id !== $authorization->id) {
            $session->forceFill([
                'telegram_chat_authorization_id' => $authorization->id,
                'locale' => $authorization->customer->default_language ?: $session->locale,
            ])->save();
        }

        $telegramUpdate->forceFill(['account_id' => $authorization->account_id])->save();

        return $authorization;
    }

    /**
     * @param  array{telegram_user_id: string, username: string}  $payload
     */
    private function linkCustomer(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, Customer $customer, string $phone, array $payload): TelegramChatAuthorization
    {
        return DB::transaction(function () use ($telegramUpdate, $session, $customer, $phone, $payload): TelegramChatAuthorization {
            $installation = $telegramUpdate->installation;
            $installation->newQuery()->whereKey($installation->id)->lockForUpdate()->firstOrFail();
            $installation->chatAuthorizations()
                ->where('customer_id', $customer->id)
                ->where('telegram_chat_id', '!=', $session->telegram_chat_id)
                ->where('status', TelegramChatAuthorizationStatus::Authorized->value)
                ->update([
                    'status' => TelegramChatAuthorizationStatus::Revoked->value,
                    'revoked_at' => now(),
                ]);
            $authorization = $installation->chatAuthorizations()->updateOrCreate(
                ['telegram_chat_id' => $session->telegram_chat_id],
                [
                    'account_id' => $installation->account_id,
                    'user_id' => null,
                    'trainer_id' => null,
                    'customer_id' => $customer->id,
                    'profile' => TelegramBotProfile::Customer->value,
                    'telegram_user_id' => $payload['telegram_user_id'],
                    'telegram_username' => $payload['username'] ?: null,
                    'phone' => $phone,
                    'status' => TelegramChatAuthorizationStatus::Authorized->value,
                    'authorized_at' => now(),
                    'revoked_at' => null,
                ],
            );
            $customer->forceFill([
                'phone_verified_at' => $customer->phone_verified_at ?? now(),
                'default_language' => $customer->default_language ?: $session->locale,
            ])->save();
            $session->forceFill([
                'telegram_chat_authorization_id' => $authorization->id,
                'locale' => $customer->default_language ?: $session->locale,
                'state' => TelegramCustomerSessionState::Idle->value,
                'encrypted_context' => null,
                'expires_at' => now()->addMinutes(self::SessionMinutes),
            ])->save();
            $telegramUpdate->forceFill(['account_id' => $installation->account_id])->save();

            return $authorization->load(['account', 'customer']);
        }, attempts: 3);
    }

    private function requestContact(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, ?string $prefix = null): bool
    {
        $session->forceFill([
            'state' => TelegramCustomerSessionState::AwaitingContact->value,
            'encrypted_context' => null,
            'expires_at' => now()->addMinutes(self::SessionMinutes),
        ])->save();
        $text = $prefix ? $prefix."\n\n".$this->t($session, 'telegram_customer_share_phone') : $this->t($session, 'telegram_customer_share_phone');
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, [
            'reply_markup' => [
                'keyboard' => [[[
                    'text' => $this->t($session, 'telegram_share_phone_button'),
                    'request_contact' => true,
                ]]],
                'resize_keyboard' => true,
                'is_persistent' => true,
                'one_time_keyboard' => false,
            ],
        ]);

        return true;
    }

    private function showMainMenu(TelegramUpdate $telegramUpdate, TelegramCustomerSession $session, TelegramChatAuthorization $authorization, string $text): bool
    {
        $this->resetSession($session, TelegramCustomerSessionState::Idle);
        $this->send($telegramUpdate, $session->telegram_chat_id, $text, [
            'reply_markup' => [
                'keyboard' => [
                    [
                        ['text' => $this->t($session, 'telegram_customer_menu_book')],
                        ['text' => $this->t($session, 'telegram_customer_menu_bookings')],
                    ],
                    [
                        ['text' => $this->t($session, 'telegram_customer_menu_passes')],
                        ['text' => $this->t($session, 'telegram_customer_menu_attendance')],
                    ],
                    [
                        ['text' => $this->t($session, 'telegram_customer_menu_studio')],
                        ['text' => $this->t($session, 'telegram_customer_menu_settings')],
                    ],
                ],
                'resize_keyboard' => true,
                'is_persistent' => true,
            ],
        ], $authorization);

        return true;
    }

    /**
     * @param  array<int, array<int, array{text: string, action: string, value?: mixed}>>  $rows
     * @param  array<string, mixed>  $context
     * @return array{reply_markup: array{inline_keyboard: array<int, array<int, array<string, mixed>>>}}
     */
    private function callbackMarkup(TelegramCustomerSession $session, TelegramCustomerSessionState $state, array $rows, array $context = [], int $ttlMinutes = self::SessionMinutes): array
    {
        $callbacks = [];
        $inlineKeyboard = collect($rows)->map(function (array $row) use (&$callbacks, $ttlMinutes): array {
            return collect($row)->map(function (array $button) use (&$callbacks, $ttlMinutes): array {
                $token = Str::random(12);
                $callbacks[$token] = [
                    'action' => $button['action'],
                    'value' => $button['value'] ?? null,
                    'expires_at' => now()->addMinutes($ttlMinutes)->timestamp,
                ];

                return [
                    'text' => $button['text'],
                    'callback_data' => 'lc:'.$token,
                ];
            })->all();
        })->all();
        $session->forceFill([
            'state' => $state->value,
            'encrypted_context' => [...$context, 'callbacks' => $callbacks],
            'expires_at' => now()->addMinutes($ttlMinutes),
            'last_interaction_at' => now(),
        ])->save();

        return ['reply_markup' => ['inline_keyboard' => $inlineKeyboard]];
    }

    /**
     * @return array{action: string, value: mixed}|null
     */
    private function callbackFor(TelegramCustomerSession $session, string $data): ?array
    {
        if (! str_starts_with($data, 'lc:')) {
            return null;
        }

        $callback = data_get($session->encrypted_context, 'callbacks.'.Str::after($data, 'lc:'));

        if (! is_array($callback) || (int) ($callback['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        return [
            'action' => (string) ($callback['action'] ?? ''),
            'value' => $callback['value'] ?? null,
        ];
    }

    private function resetSession(TelegramCustomerSession $session, TelegramCustomerSessionState $state): void
    {
        $session->forceFill([
            'state' => $state->value,
            'encrypted_context' => null,
            'expires_at' => now()->addMinutes(self::SessionMinutes),
            'last_interaction_at' => now(),
        ])->save();
    }

    /**
     * @param  array{chat_id: string, telegram_user_id: string, message_id: string, text: string, message: array<string, mixed>}  $payload
     */
    private function storeInbound(TelegramUpdate $telegramUpdate, ?TelegramChatAuthorization $authorization, array $payload): void
    {
        TelegramMessage::query()->firstOrCreate(
            ['telegram_update_id' => $telegramUpdate->id, 'direction' => 'inbound'],
            [
                'account_id' => $authorization?->account_id ?? $telegramUpdate->account_id,
                'telegram_bot_installation_id' => $telegramUpdate->telegram_bot_installation_id,
                'telegram_chat_authorization_id' => $authorization?->id,
                'profile' => TelegramBotProfile::Customer->value,
                'telegram_chat_id' => $payload['chat_id'],
                'telegram_message_id' => $payload['message_id'],
                'telegram_user_id' => $payload['telegram_user_id'],
                'message_type' => is_array(data_get($payload, 'message.contact')) ? 'contact' : 'text',
                'text' => $payload['text'] ?: null,
                'payload' => $payload['message'],
                'sent_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function send(TelegramUpdate $telegramUpdate, string $chatId, string $text, array $extra = [], ?TelegramChatAuthorization $authorization = null): TelegramMessage
    {
        $response = $this->telegramClient->sendMessage($telegramUpdate->installation, $chatId, $text, [
            'disable_web_page_preview' => true,
            ...$extra,
        ]);

        if (! $this->telegramOk($response)) {
            throw new RuntimeException((string) ($response?->json('description') ?: 'Telegram customer message delivery failed.'));
        }

        return TelegramMessage::query()->firstOrCreate(
            ['telegram_update_id' => $telegramUpdate->id, 'direction' => 'outbound'],
            [
                'account_id' => $authorization?->account_id ?? $telegramUpdate->account_id,
                'telegram_bot_installation_id' => $telegramUpdate->telegram_bot_installation_id,
                'telegram_chat_authorization_id' => $authorization?->id,
                'profile' => TelegramBotProfile::Customer->value,
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => filled($response?->json('result.message_id')) ? (string) $response?->json('result.message_id') : null,
                'direction' => 'outbound',
                'message_type' => 'text',
                'text' => $text,
                'payload' => $extra ?: null,
                'sent_at' => now(),
            ],
        );
    }

    /**
     * @return array{kind: string, chat_id: string, chat_type: string, telegram_user_id: string, username: string, language_code: string, text: string, message_id: string, message: array<string, mixed>, callback_id: string, callback_data: string}|null
     */
    private function payloadContext(TelegramUpdate $telegramUpdate): ?array
    {
        $callback = data_get($telegramUpdate->payload, 'callback_query');

        if (is_array($callback)) {
            $message = data_get($callback, 'message', []);

            return [
                'kind' => 'callback',
                'chat_id' => (string) data_get($message, 'chat.id', ''),
                'chat_type' => (string) data_get($message, 'chat.type', ''),
                'telegram_user_id' => (string) data_get($callback, 'from.id', ''),
                'username' => (string) data_get($callback, 'from.username', ''),
                'language_code' => (string) data_get($callback, 'from.language_code', ''),
                'text' => '',
                'message_id' => (string) data_get($message, 'message_id', ''),
                'message' => is_array($message) ? $message : [],
                'callback_id' => (string) data_get($callback, 'id', ''),
                'callback_data' => (string) data_get($callback, 'data', ''),
            ];
        }

        $message = data_get($telegramUpdate->payload, 'message');

        if (! is_array($message)) {
            return null;
        }

        return [
            'kind' => 'message',
            'chat_id' => (string) data_get($message, 'chat.id', ''),
            'chat_type' => (string) data_get($message, 'chat.type', ''),
            'telegram_user_id' => (string) data_get($message, 'from.id', ''),
            'username' => (string) data_get($message, 'from.username', ''),
            'language_code' => (string) data_get($message, 'from.language_code', ''),
            'text' => trim((string) (data_get($message, 'text') ?? '')),
            'message_id' => (string) data_get($message, 'message_id', ''),
            'message' => $message,
            'callback_id' => '',
            'callback_data' => '',
        ];
    }

    private function accountCanUseBot(Account $account): bool
    {
        return $account->status === AccountStatus::Active
            && $account->mode === AccountMode::Live
            && $this->subscriptionAccess->canUsePublicFeatures($account)
            && $account->telegramBotProfiles()
                ->where('profile', TelegramBotProfile::Customer->value)
                ->where('mode', TelegramBotMode::Simple->value)
                ->where('is_enabled', true)
                ->exists();
    }

    private function validFullName(string $name): bool
    {
        return mb_strlen($name) >= 3
            && mb_strlen($name) <= 255
            && preg_match("/\A[\p{L}\p{M}'’ʼ-]+(?:\s+[\p{L}\p{M}'’ʼ-]+)+\z/u", $name) === 1;
    }

    private function localeFromTelegram(Account $account, string $languageCode): string
    {
        $candidate = Str::lower(Str::before($languageCode, '-'));

        if (array_key_exists($candidate, config('ladna.locales', []))) {
            return $candidate;
        }

        return array_key_exists((string) $account->default_language, config('ladna.locales', []))
            ? (string) $account->default_language
            : 'uk';
    }

    private function command(string $text): ?string
    {
        if (preg_match('/^\/([a-z_]+)(?:@\w+)?(?:\s+.*)?$/i', trim($text), $matches) !== 1) {
            return null;
        }

        return Str::lower($matches[1]);
    }

    private function menuAction(string $text, ?string $command): ?string
    {
        $commands = [
            'book' => 'book',
            'bookings' => 'bookings',
            'passes' => 'passes',
            'attendance' => 'attendance',
            'studio' => 'studio',
            'language' => 'language',
            'unlink' => 'unlink',
        ];

        if ($command && isset($commands[$command])) {
            return $commands[$command];
        }

        foreach (array_keys(config('ladna.locales', [])) as $locale) {
            foreach ([
                'telegram_customer_menu_book' => 'book',
                'telegram_customer_menu_bookings' => 'bookings',
                'telegram_customer_menu_passes' => 'passes',
                'telegram_customer_menu_attendance' => 'attendance',
                'telegram_customer_menu_studio' => 'studio',
                'telegram_customer_menu_settings' => 'language',
            ] as $key => $action) {
                if (hash_equals(__('app.'.$key, [], $locale), $text)) {
                    return $action;
                }
            }
        }

        return null;
    }

    private function isTelegramButtonUrl(string $url): bool
    {
        return Str::startsWith(Str::lower($url), ['http://', 'https://', 'tg://']);
    }

    private function calendarUrl(ScheduledClass $class): string
    {
        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $class->displayTitle(),
            'dates' => $class->starts_at->copy()->utc()->format('Ymd\THis\Z').'/'.$class->ends_at->copy()->utc()->format('Ymd\THis\Z'),
            'location' => collect([$class->location?->name, $class->location?->address, $class->room?->name])->filter()->implode(', '),
        ]);
    }

    /**
     * @param  array<string, mixed>  $replace
     */
    private function t(TelegramCustomerSession $session, string $key, array $replace = []): string
    {
        return __('app.'.$key, $replace, $session->locale);
    }

    private function telegramOk(?Response $response): bool
    {
        return $response?->successful() === true && $response->json('ok') === true;
    }
}
