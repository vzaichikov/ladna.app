<?php

namespace App\Support\Mail;

use App\Enums\AccountRole;
use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Enums\EmailScenario;
use App\Enums\EventTicketStatus;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\CustomerPurchase;
use App\Models\EventOrder;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Events\EventQrCode;
use App\Support\MoneyFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class TransactionalMailDispatcher
{
    public function __construct(
        private readonly MailDeliverySettingsResolver $settingsResolver,
        private readonly EmailScenarioSettings $scenarioSettings,
        private readonly EmailDeliveryRecorder $deliveryRecorder,
    ) {}

    public function eventTicketsIssued(EventOrder $order): void
    {
        $order->loadMissing(['account', 'event.location', 'event.rooms', 'tickets.ticketType']);

        $validTickets = $order->tickets->where('status', EventTicketStatus::Valid);

        if (! $order->account || ! $order->event || $validTickets->isEmpty()) {
            return;
        }

        $qr = app(EventQrCode::class);
        $tickets = $validTickets->map(function ($ticket) use ($order, $qr): array {
            $png = $qr->png($ticket);

            return [
                'type' => $ticket->ticketType?->name,
                'code' => $ticket->code,
                'qr_data' => base64_encode($png),
                'qr_url' => route('public.event-tickets.qr', [
                    $order->account->slug,
                    $order->access_token_encrypted,
                    $ticket->code,
                ]),
            ];
        });
        $data = [
            ...$this->accountData($order->account),
            'recipient_name' => $this->recipientName($order->buyer_name),
            'event_title' => $order->event->title,
            'event_time' => $this->eventTime($order),
            'event_venue' => $this->eventVenue($order),
            'tickets' => $tickets->all(),
            'action_url' => route('public.event-orders.show', [$order->account->slug, $order->access_token_encrypted]),
        ];
        $attachments = $tickets->map(fn (array $ticket): array => [
            'name' => $ticket['code'].'.png',
            'mime' => 'image/png',
            'data' => $ticket['qr_data'],
        ])->all();

        $this->sendToAddress(
            email: $order->buyer_email,
            name: $order->buyer_name,
            account: $order->account,
            scenario: EmailScenario::EventTicketsIssued,
            mail: new TransactionalMail(
                subjectKey: EmailScenario::EventTicketsIssued->subjectKey(),
                contentView: EmailScenario::EventTicketsIssued->contentView(),
                data: $data,
                subjectParameters: ['event' => $order->event->title, 'studio' => $order->account->name],
                attachmentData: $attachments,
            ),
            locale: $order->locale,
            eventOrder: $order,
        );
    }

    public function eventBuyerNotice(EventOrder $order, EmailScenario $scenario): void
    {
        abort_unless(in_array($scenario, [
            EmailScenario::EventUpdated,
            EmailScenario::EventCancelled,
            EmailScenario::EventPaymentAttention,
        ], true), 500);
        $order->loadMissing(['account', 'event.location', 'event.rooms']);

        if (! $order->account || ! $order->event) {
            return;
        }

        $data = [
            ...$this->accountData($order->account),
            'recipient_name' => $this->recipientName($order->buyer_name),
            'event_title' => $order->event->title,
            'event_time' => $this->eventTime($order),
            'event_venue' => $this->eventVenue($order),
            'amount' => $order->amount_cents > 0 ? MoneyFormatter::format($order->amount_cents, $order->currency) : null,
            'action_url' => route('public.event-orders.show', [$order->account->slug, $order->access_token_encrypted]),
        ];

        $this->sendToAddress(
            email: $order->buyer_email,
            name: $order->buyer_name,
            account: $order->account,
            scenario: $scenario,
            mail: new TransactionalMail(
                subjectKey: $scenario->subjectKey(),
                contentView: $scenario->contentView(),
                data: $data,
                subjectParameters: ['event' => $order->event->title, 'studio' => $order->account->name],
            ),
            locale: $order->locale,
            eventOrder: $order,
        );
    }

    private function eventTime(EventOrder $order): string
    {
        return $order->event->starts_at->copy()->timezone($order->event->timezone)->format('Y-m-d H:i')
            .' - '.$order->event->ends_at->copy()->timezone($order->event->timezone)->format('H:i');
    }

    private function eventVenue(EventOrder $order): string
    {
        return $order->event->venue_kind->value === 'studio'
            ? collect([$order->event->location?->name, $order->event->rooms->pluck('name')->join(', ')])->filter()->join(' · ')
            : collect([$order->event->external_venue_name, $order->event->external_address])->filter()->join(' · ');
    }

    public function customerClassPassIssued(CustomerClassPass $classPass): void
    {
        $classPass->loadMissing(['account', 'customer']);

        if (! $classPass->account || ! $classPass->customer) {
            return;
        }

        $data = [
            ...$this->accountData($classPass->account),
            'recipient_name' => $this->recipientName($classPass->customer->name),
            'pass_name' => $classPass->plan_name,
            'pass_code' => $classPass->code,
            'sessions_count' => (string) $classPass->sessions_count,
            'remaining_sessions_count' => (string) $classPass->remainingSessionsCount(),
            'expires_at' => $this->formatDate($classPass->expires_at, $classPass->account),
            'usable_until_at' => $this->formatDate($classPass->usableUntilAt(), $classPass->account),
            'amount' => MoneyFormatter::format($classPass->price_cents, $classPass->currency),
            'action_url' => route('customer.dashboard', $classPass->account->slug),
        ];

        $this->sendToCustomer(
            $classPass->customer,
            $classPass->account,
            EmailScenario::CustomerClassPassIssued,
            new TransactionalMail(
                subjectKey: EmailScenario::CustomerClassPassIssued->subjectKey(),
                contentView: EmailScenario::CustomerClassPassIssued->contentView(),
                data: $data,
                subjectParameters: ['pass' => $classPass->plan_name, 'studio' => $classPass->account->name],
            ),
        );
    }

    public function customerPurchaseFailed(CustomerPurchase $purchase): void
    {
        $purchase->loadMissing(['account', 'customer']);

        if (! $purchase->account || ! $purchase->customer || $purchase->status === CustomerPurchaseStatus::PaymentPaid) {
            return;
        }

        $data = [
            ...$this->accountData($purchase->account),
            'recipient_name' => $this->recipientName($purchase->customer->name),
            'pass_name' => $purchase->plan_name,
            'status' => __('app.'.$purchase->status->value),
            'amount' => MoneyFormatter::format($purchase->amount_cents, $purchase->currency),
            'failure_reason' => $purchase->failure_reason,
            'action_url' => route('customer.dashboard', $purchase->account->slug),
        ];

        $this->sendToCustomer(
            $purchase->customer,
            $purchase->account,
            EmailScenario::CustomerPurchaseFailed,
            new TransactionalMail(
                subjectKey: EmailScenario::CustomerPurchaseFailed->subjectKey(),
                contentView: EmailScenario::CustomerPurchaseFailed->contentView(),
                data: $data,
                subjectParameters: ['pass' => $purchase->plan_name, 'studio' => $purchase->account->name],
            ),
        );
    }

    public function bookingCreated(ClassBooking $booking): void
    {
        $booking->loadMissing([
            'account',
            'customer',
            'scheduledClass.account',
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.trainer',
        ]);

        if (! $booking->account || ! $booking->customer || ! $booking->scheduledClass) {
            return;
        }

        $data = [
            ...$this->accountData($booking->account),
            ...$this->scheduledClassData($booking->scheduledClass),
            'recipient_name' => $this->recipientName($booking->customer->name),
            'action_url' => route('customer.dashboard', $booking->account->slug),
        ];

        $this->sendToCustomer(
            $booking->customer,
            $booking->account,
            EmailScenario::BookingCreated,
            new TransactionalMail(
                subjectKey: EmailScenario::BookingCreated->subjectKey(),
                contentView: EmailScenario::BookingCreated->contentView(),
                data: $data,
                subjectParameters: ['class' => $booking->scheduledClass->title, 'studio' => $booking->account->name],
            ),
        );
    }

    public function bookingCancelled(ClassBooking $booking): void
    {
        $booking->loadMissing([
            'account',
            'customer',
            'scheduledClass.account',
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.trainer',
        ]);

        if (! $booking->account || ! $booking->customer || ! $booking->scheduledClass) {
            return;
        }

        $data = [
            ...$this->accountData($booking->account),
            ...$this->scheduledClassData($booking->scheduledClass),
            'recipient_name' => $this->recipientName($booking->customer->name),
            'action_url' => $this->scheduleUrl($booking->scheduledClass),
        ];

        $this->sendToCustomer(
            $booking->customer,
            $booking->account,
            EmailScenario::BookingCancelled,
            new TransactionalMail(
                subjectKey: EmailScenario::BookingCancelled->subjectKey(),
                contentView: EmailScenario::BookingCancelled->contentView(),
                data: $data,
                subjectParameters: ['class' => $booking->scheduledClass->title, 'studio' => $booking->account->name],
            ),
        );
    }

    public function scheduledClassCancelled(ScheduledClassCancellation $cancellation): void
    {
        $cancellation->loadMissing([
            'account',
            'scheduledClass.account',
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.trainer',
            'effects.classBooking.customer',
        ]);

        if (! $cancellation->account || ! $cancellation->scheduledClass) {
            return;
        }

        $this->customersFromCancellation($cancellation)
            ->each(function (Customer $customer) use ($cancellation): void {
                $data = [
                    ...$this->accountData($cancellation->account),
                    ...$this->scheduledClassData($cancellation->scheduledClass),
                    'recipient_name' => $this->recipientName($customer->name),
                    'action_url' => $this->scheduleUrl($cancellation->scheduledClass),
                ];

                $this->sendToCustomer(
                    $customer,
                    $cancellation->account,
                    EmailScenario::ScheduledClassCancelled,
                    new TransactionalMail(
                        subjectKey: EmailScenario::ScheduledClassCancelled->subjectKey(),
                        contentView: EmailScenario::ScheduledClassCancelled->contentView(),
                        data: $data,
                        subjectParameters: ['class' => $cancellation->scheduledClass->title, 'studio' => $cancellation->account->name],
                    ),
                );
            });
    }

    public function scheduledClassRestored(ScheduledClassCancellation $cancellation): void
    {
        $cancellation->loadMissing([
            'account',
            'scheduledClass.account',
            'scheduledClass.location',
            'scheduledClass.room',
            'scheduledClass.trainer',
            'effects.classBooking.customer',
        ]);

        if (! $cancellation->account || ! $cancellation->scheduledClass) {
            return;
        }

        $this->customersFromCancellation($cancellation)
            ->each(function (Customer $customer) use ($cancellation): void {
                $data = [
                    ...$this->accountData($cancellation->account),
                    ...$this->scheduledClassData($cancellation->scheduledClass),
                    'recipient_name' => $this->recipientName($customer->name),
                    'action_url' => route('customer.dashboard', $cancellation->account->slug),
                ];

                $this->sendToCustomer(
                    $customer,
                    $cancellation->account,
                    EmailScenario::ScheduledClassRestored,
                    new TransactionalMail(
                        subjectKey: EmailScenario::ScheduledClassRestored->subjectKey(),
                        contentView: EmailScenario::ScheduledClassRestored->contentView(),
                        data: $data,
                        subjectParameters: ['class' => $cancellation->scheduledClass->title, 'studio' => $cancellation->account->name],
                    ),
                );
            });
    }

    public function classPassAdjusted(CustomerClassPassAdjustment $adjustment): void
    {
        $adjustment->loadMissing([
            'account',
            'customerClassPass.account',
            'customerClassPass.customer',
        ]);

        $classPass = $adjustment->customerClassPass;
        $account = $adjustment->account ?? $classPass?->account;
        $customer = $classPass?->customer;

        if (! $account || ! $customer || ! $classPass) {
            return;
        }

        $data = [
            ...$this->accountData($account),
            'recipient_name' => $this->recipientName($customer->name),
            'pass_name' => $classPass->plan_name,
            'pass_code' => $classPass->code,
            'sessions_delta' => $this->signedValue($adjustment->sessions_delta),
            'previous_sessions_count' => (string) $adjustment->previous_sessions_count,
            'new_sessions_count' => (string) $adjustment->new_sessions_count,
            'days_delta' => $this->signedValue($adjustment->days_delta),
            'previous_validity_days' => (string) $adjustment->previous_validity_days,
            'new_validity_days' => (string) $adjustment->new_validity_days,
            'previous_status' => $this->statusLabel($adjustment->previous_status),
            'new_status' => $this->statusLabel($adjustment->new_status),
            'freeze_started_at' => $this->formatDateTime($adjustment->freeze_started_at, $account),
            'freeze_finished_at' => $this->formatDateTime($adjustment->freeze_finished_at, $account),
            'freeze_days_count' => (string) $adjustment->freeze_days_count,
            'reason' => $adjustment->reason,
            'action_url' => route('customer.dashboard', $account->slug),
        ];

        $this->sendToCustomer(
            $customer,
            $account,
            EmailScenario::ClassPassAdjusted,
            new TransactionalMail(
                subjectKey: EmailScenario::ClassPassAdjusted->subjectKey(),
                contentView: EmailScenario::ClassPassAdjusted->contentView(),
                data: $data,
                subjectParameters: ['pass' => $classPass->plan_name, 'studio' => $account->name],
            ),
        );
    }

    public function saasPaymentResolved(AccountSubscriptionPayment $payment): void
    {
        $payment->loadMissing(['account', 'subscription.plan', 'plan']);

        if (! $payment->account || ! $payment->status->isFinal()) {
            return;
        }

        $scenario = $payment->status === AccountSubscriptionPaymentStatus::PaymentPaid
            ? EmailScenario::SaasPaymentPaid
            : EmailScenario::SaasPaymentFailed;

        $baseData = [
            ...$this->accountData($payment->account),
            'plan_name' => $payment->plan_name_snapshot ?: ($payment->plan?->name ?? $payment->subscription?->plan?->name),
            'locations' => $payment->billable_location_count,
            'status' => __('app.'.$payment->status->value),
            'amount' => MoneyFormatter::format($payment->amount_cents, $payment->currency),
            'period' => $this->period($payment->period_starts_at, $payment->period_ends_at, $payment->account),
            'failure_reason' => $payment->failure_reason,
            'action_url' => route('dashboard.accounts.tariff-payments.show', $payment->account),
        ];

        $this->sendToAccountOwners($payment->account, $scenario, function (User $user) use ($baseData, $scenario, $payment): TransactionalMail {
            return new TransactionalMail(
                subjectKey: $scenario->subjectKey(),
                contentView: $scenario->contentView(),
                data: [
                    ...$baseData,
                    'recipient_name' => $this->recipientName($user->name),
                ],
                subjectParameters: ['studio' => $payment->account->name],
            );
        });
    }

    public function saasSubscriptionExpired(AccountSubscription $subscription): void
    {
        $subscription->loadMissing(['account', 'plan']);

        if (! $subscription->account) {
            return;
        }

        $baseData = [
            ...$this->accountData($subscription->account),
            'plan_name' => $subscription->plan?->name,
            'period_ends_at' => $this->formatDate($subscription->ends_at, $subscription->account),
            'action_url' => route('dashboard.accounts.tariff-payments.show', $subscription->account),
        ];

        $scenario = EmailScenario::SaasSubscriptionExpired;

        $this->sendToAccountOwners($subscription->account, $scenario, function (User $user) use ($baseData, $scenario, $subscription): TransactionalMail {
            return new TransactionalMail(
                subjectKey: $scenario->subjectKey(),
                contentView: $scenario->contentView(),
                data: [
                    ...$baseData,
                    'recipient_name' => $this->recipientName($user->name),
                ],
                subjectParameters: ['studio' => $subscription->account->name],
            );
        });
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     */
    public function saasLifecycleNotice(AccountSubscription $subscription, string $type, array $parameters = []): void
    {
        $subscription->loadMissing(['account', 'plan']);

        if (! $subscription->account) {
            return;
        }

        $scenario = EmailScenario::fromLifecycleType($type);
        $baseData = [
            ...$this->accountData($subscription->account),
            'notice_type' => $type,
            'notice_parameters' => $parameters,
            'plan_name' => $subscription->plan?->name,
            'action_url' => route('dashboard.accounts.tariff-payments.show', $subscription->account),
        ];

        $this->sendToAccountOwners($subscription->account, $scenario, function (User $user) use ($baseData, $scenario, $subscription): TransactionalMail {
            return new TransactionalMail(
                subjectKey: $scenario->subjectKey(),
                contentView: $scenario->contentView(),
                data: [
                    ...$baseData,
                    'recipient_name' => $this->recipientName($user->name),
                ],
                subjectParameters: ['studio' => $subscription->account->name],
            );
        });
    }

    private function sendToCustomer(
        Customer $customer,
        Account $account,
        EmailScenario $scenario,
        TransactionalMail $mail,
    ): void {
        $this->sendToAddress(
            email: $customer->email,
            name: $customer->name,
            account: $account,
            scenario: $scenario,
            mail: $mail,
            locale: $customer->default_language ?: $account->default_language,
            customer: $customer,
        );
    }

    /**
     * @param  callable(User): TransactionalMail  $mailFactory
     */
    private function sendToAccountOwners(Account $account, EmailScenario $scenario, callable $mailFactory): void
    {
        $account->users()
            ->wherePivot('role', AccountRole::Owner->value)
            ->get()
            ->filter(fn (User $user): bool => filled($user->email))
            ->unique(fn (User $user): string => mb_strtolower($user->email))
            ->each(function (User $user) use ($account, $scenario, $mailFactory): void {
                $this->sendToAddress(
                    email: $user->email,
                    name: $user->name,
                    account: $account,
                    scenario: $scenario,
                    mail: $mailFactory($user),
                    locale: $account->default_language,
                    user: $user,
                );
            });
    }

    private function sendToAddress(
        ?string $email,
        ?string $name,
        Account $account,
        EmailScenario $scenario,
        TransactionalMail $mail,
        ?string $locale = null,
        ?Customer $customer = null,
        ?User $user = null,
        ?EventOrder $eventOrder = null,
    ): void {
        if ($account->isReadOnlyDemo() || ! $this->scenarioSettings->isEnabled($scenario)) {
            return;
        }

        $email = trim((string) $email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $settings = $this->settingsResolver->resolve();
        $locale = $this->locale($locale ?: $account->default_language);
        $delivery = $this->deliveryRecorder->createPending(
            account: $account,
            customer: $customer,
            user: $user,
            scenario: $scenario,
            email: $email,
            name: $name,
            locale: $locale,
            mail: $mail,
            settings: $settings,
            eventOrder: $eventOrder,
        );

        $mail
            ->from($settings->fromEmail, $settings->fromName)
            ->locale($locale)
            ->forEmailDelivery($delivery->id);

        Mail::mailer($settings->mailer)->to($email, $name ?: $email)->send($mail);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(Account $account): array
    {
        return [
            'account_name' => $account->name,
            'account_logo_url' => $account->logoUrl(),
            'account_brand_color' => $account->brand_color ?: '#6d28d9',
            'support_url' => SystemSetting::stringValue(SystemSetting::SupportUrlKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledClassData(ScheduledClass $scheduledClass): array
    {
        $scheduledClass->loadMissing(['account', 'location', 'room', 'trainer']);

        return [
            'class_title' => $scheduledClass->title,
            'class_time' => $this->classTime($scheduledClass),
            'location_name' => $scheduledClass->location?->name,
            'room_name' => $scheduledClass->room?->name,
            'trainer_name' => $scheduledClass->trainer?->name,
        ];
    }

    private function classTime(ScheduledClass $scheduledClass): string
    {
        $timezone = $scheduledClass->displayTimezone();
        $startsAt = $scheduledClass->starts_at->copy()->timezone($timezone);
        $endsAt = $scheduledClass->ends_at->copy()->timezone($timezone);

        if ($startsAt->isSameDay($endsAt)) {
            return $startsAt->format('Y-m-d H:i').' - '.$endsAt->format('H:i');
        }

        return $startsAt->format('Y-m-d H:i').' - '.$endsAt->format('Y-m-d H:i');
    }

    private function formatDate(?Carbon $date, Account $account): ?string
    {
        return $date?->copy()
            ->timezone($account->timezone ?? config('app.timezone'))
            ->format('Y-m-d');
    }

    private function formatDateTime(?Carbon $date, Account $account): ?string
    {
        return $date?->copy()
            ->timezone($account->timezone ?? config('app.timezone'))
            ->format('Y-m-d H:i');
    }

    private function signedValue(?int $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value > 0 ? '+'.$value : (string) $value;
    }

    private function statusLabel(?string $status): ?string
    {
        return $status ? __('app.'.$status) : null;
    }

    private function period(?Carbon $startsAt, ?Carbon $endsAt, Account $account): ?string
    {
        $start = $this->formatDate($startsAt, $account);
        $end = $this->formatDate($endsAt, $account);

        if (! $start && ! $end) {
            return null;
        }

        return trim(($start ?? '').' - '.($end ?? ''), ' -');
    }

    private function scheduleUrl(ScheduledClass $scheduledClass): string
    {
        $scheduledClass->loadMissing(['account', 'location']);

        if ($scheduledClass->account && $scheduledClass->location) {
            return route('public.schedule', [$scheduledClass->account->slug, $scheduledClass->location->slug]);
        }

        return route('home');
    }

    /**
     * @return Collection<int, Customer>
     */
    private function customersFromCancellation(ScheduledClassCancellation $cancellation): Collection
    {
        return $cancellation->effects
            ->map(fn ($effect): ?Customer => $effect->classBooking?->customer)
            ->filter()
            ->filter(fn (Customer $customer): bool => filled($customer->email))
            ->unique(fn (Customer $customer): int => $customer->id)
            ->values();
    }

    private function recipientName(?string $name): string
    {
        return filled($name) ? (string) $name : __('app.mail_customer');
    }

    private function locale(?string $locale): string
    {
        $locale = (string) $locale;

        return array_key_exists($locale, config('ladna.locales', [])) ? $locale : config('app.locale');
    }
}
