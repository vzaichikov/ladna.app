<?php

namespace App\Support\Mail;

use App\Enums\AccountSubscriptionPaymentStatus;
use App\Enums\CustomerPurchaseStatus;
use App\Mail\TransactionalMail;
use App\Models\Account;
use App\Models\AccountSubscription;
use App\Models\AccountSubscriptionPayment;
use App\Models\ClassBooking;
use App\Models\Customer;
use App\Models\CustomerClassPass;
use App\Models\CustomerClassPassAdjustment;
use App\Models\CustomerPurchase;
use App\Models\ScheduledClass;
use App\Models\ScheduledClassCancellation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class TransactionalMailDispatcher
{
    public function __construct(
        private readonly MailDeliverySettingsResolver $settingsResolver,
    ) {}

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
            new TransactionalMail(
                subjectKey: 'app.mail_subject_customer_class_pass_issued',
                contentView: 'mail.content.customer-class-pass-issued',
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
            new TransactionalMail(
                subjectKey: 'app.mail_subject_customer_purchase_failed',
                contentView: 'mail.content.customer-purchase-failed',
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
            new TransactionalMail(
                subjectKey: 'app.mail_subject_booking_created',
                contentView: 'mail.content.booking-created',
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
            new TransactionalMail(
                subjectKey: 'app.mail_subject_booking_cancelled',
                contentView: 'mail.content.booking-cancelled',
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
                    new TransactionalMail(
                        subjectKey: 'app.mail_subject_scheduled_class_cancelled',
                        contentView: 'mail.content.scheduled-class-cancelled',
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
                    new TransactionalMail(
                        subjectKey: 'app.mail_subject_scheduled_class_restored',
                        contentView: 'mail.content.scheduled-class-restored',
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
            'sessions_delta' => (string) $adjustment->sessions_delta,
            'previous_sessions_count' => (string) $adjustment->previous_sessions_count,
            'new_sessions_count' => (string) $adjustment->new_sessions_count,
            'reason' => $adjustment->reason,
            'action_url' => route('customer.dashboard', $account->slug),
        ];

        $this->sendToCustomer(
            $customer,
            $account,
            new TransactionalMail(
                subjectKey: 'app.mail_subject_class_pass_adjusted',
                contentView: 'mail.content.class-pass-adjusted',
                data: $data,
                subjectParameters: ['pass' => $classPass->plan_name, 'studio' => $account->name],
            ),
        );
    }

    public function saasPaymentResolved(AccountSubscriptionPayment $payment): void
    {
        $payment->loadMissing(['account.users', 'subscription.plan', 'plan']);

        if (! $payment->account || ! $payment->status->isFinal()) {
            return;
        }

        $subjectKey = $payment->status === AccountSubscriptionPaymentStatus::PaymentPaid
            ? 'app.mail_subject_saas_payment_paid'
            : 'app.mail_subject_saas_payment_failed';
        $contentView = $payment->status === AccountSubscriptionPaymentStatus::PaymentPaid
            ? 'mail.content.saas-payment-paid'
            : 'mail.content.saas-payment-failed';

        $baseData = [
            ...$this->accountData($payment->account),
            'plan_name' => $payment->plan?->name ?? $payment->subscription?->plan?->name,
            'status' => __('app.'.$payment->status->value),
            'amount' => MoneyFormatter::format($payment->amount_cents, $payment->currency),
            'period' => $this->period($payment->period_starts_at, $payment->period_ends_at, $payment->account),
            'failure_reason' => $payment->failure_reason,
            'action_url' => route('dashboard.accounts.tariff-payments.show', $payment->account),
        ];

        $this->sendToAccountOwners($payment->account, function (User $user) use ($baseData, $subjectKey, $contentView, $payment): TransactionalMail {
            return new TransactionalMail(
                subjectKey: $subjectKey,
                contentView: $contentView,
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
        $subscription->loadMissing(['account.users', 'plan']);

        if (! $subscription->account) {
            return;
        }

        $baseData = [
            ...$this->accountData($subscription->account),
            'plan_name' => $subscription->plan?->name,
            'period_ends_at' => $this->formatDate($subscription->ends_at, $subscription->account),
            'action_url' => route('dashboard.accounts.tariff-payments.show', $subscription->account),
        ];

        $this->sendToAccountOwners($subscription->account, function (User $user) use ($baseData, $subscription): TransactionalMail {
            return new TransactionalMail(
                subjectKey: 'app.mail_subject_saas_subscription_expired',
                contentView: 'mail.content.saas-subscription-expired',
                data: [
                    ...$baseData,
                    'recipient_name' => $this->recipientName($user->name),
                ],
                subjectParameters: ['studio' => $subscription->account->name],
            );
        });
    }

    private function sendToCustomer(Customer $customer, Account $account, TransactionalMail $mail): void
    {
        $this->sendToAddress(
            email: $customer->email,
            name: $customer->name,
            account: $account,
            mail: $mail,
            locale: $customer->default_language ?: $account->default_language,
        );
    }

    /**
     * @param  callable(User): TransactionalMail  $mailFactory
     */
    private function sendToAccountOwners(Account $account, callable $mailFactory): void
    {
        $account->loadMissing('users');

        $account->users
            ->filter(fn (User $user): bool => filled($user->email))
            ->unique(fn (User $user): string => mb_strtolower($user->email))
            ->each(function (User $user) use ($account, $mailFactory): void {
                $this->sendToAddress(
                    email: $user->email,
                    name: $user->name,
                    account: $account,
                    mail: $mailFactory($user),
                    locale: $account->default_language,
                );
            });
    }

    private function sendToAddress(?string $email, ?string $name, Account $account, TransactionalMail $mail, ?string $locale = null): void
    {
        $email = trim((string) $email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $settings = $this->settingsResolver->resolve();

        $mail
            ->from($settings->fromEmail, $settings->fromName)
            ->locale($this->locale($locale ?: $account->default_language));

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
