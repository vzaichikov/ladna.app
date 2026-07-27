<?php

namespace App\Enums;

use ValueError;

enum EmailScenario: string
{
    case CustomerClassPassIssued = 'customer_class_pass_issued';
    case CustomerPurchaseFailed = 'customer_purchase_failed';
    case BookingCreated = 'booking_created';
    case BookingCancelled = 'booking_cancelled';
    case ScheduledClassCancelled = 'scheduled_class_cancelled';
    case ScheduledClassRestored = 'scheduled_class_restored';
    case ClassPassAdjusted = 'class_pass_adjusted';
    case SaasPaymentPaid = 'saas_payment_paid';
    case SaasPaymentFailed = 'saas_payment_failed';
    case SaasSubscriptionExpired = 'saas_subscription_expired';
    case SaasTrialEnding7 = 'saas_trial_ending_7';
    case SaasTrialEnding3 = 'saas_trial_ending_3';
    case SaasTrialEnding1 = 'saas_trial_ending_1';
    case SaasAnnualRenewal = 'saas_annual_renewal';
    case SaasPriceChange = 'saas_price_change';
    case SaasGraceExpiry = 'saas_grace_expiry';
    case SaasCancellation = 'saas_cancellation';
    case SaasReactivation = 'saas_reactivation';
    case SaasTariffChange = 'saas_tariff_change';

    public function group(): EmailScenarioGroup
    {
        return match ($this) {
            self::BookingCreated,
            self::BookingCancelled,
            self::ScheduledClassCancelled,
            self::ScheduledClassRestored => EmailScenarioGroup::CustomerBookings,

            self::CustomerClassPassIssued,
            self::CustomerPurchaseFailed,
            self::ClassPassAdjusted => EmailScenarioGroup::CustomerPasses,

            self::SaasPaymentPaid,
            self::SaasPaymentFailed,
            self::SaasSubscriptionExpired,
            self::SaasAnnualRenewal,
            self::SaasGraceExpiry => EmailScenarioGroup::SubscriptionPayments,

            self::SaasTrialEnding7,
            self::SaasTrialEnding3,
            self::SaasTrialEnding1,
            self::SaasPriceChange,
            self::SaasCancellation,
            self::SaasReactivation,
            self::SaasTariffChange => EmailScenarioGroup::SubscriptionLifecycle,
        };
    }

    public function recipientKind(): EmailRecipientKind
    {
        return match ($this->group()) {
            EmailScenarioGroup::CustomerBookings,
            EmailScenarioGroup::CustomerPasses => EmailRecipientKind::Customer,
            EmailScenarioGroup::SubscriptionPayments,
            EmailScenarioGroup::SubscriptionLifecycle => EmailRecipientKind::StudioOwner,
        };
    }

    public function subjectKey(): string
    {
        return 'app.mail_subject_'.$this->value;
    }

    public function contentView(): string
    {
        return match ($this) {
            self::CustomerClassPassIssued => 'mail.content.customer-class-pass-issued',
            self::CustomerPurchaseFailed => 'mail.content.customer-purchase-failed',
            self::BookingCreated => 'mail.content.booking-created',
            self::BookingCancelled => 'mail.content.booking-cancelled',
            self::ScheduledClassCancelled => 'mail.content.scheduled-class-cancelled',
            self::ScheduledClassRestored => 'mail.content.scheduled-class-restored',
            self::ClassPassAdjusted => 'mail.content.class-pass-adjusted',
            self::SaasPaymentPaid => 'mail.content.saas-payment-paid',
            self::SaasPaymentFailed => 'mail.content.saas-payment-failed',
            self::SaasSubscriptionExpired => 'mail.content.saas-subscription-expired',
            self::SaasTrialEnding7,
            self::SaasTrialEnding3,
            self::SaasTrialEnding1,
            self::SaasAnnualRenewal,
            self::SaasPriceChange,
            self::SaasGraceExpiry,
            self::SaasCancellation,
            self::SaasReactivation,
            self::SaasTariffChange => 'mail.content.saas-lifecycle-notice',
        };
    }

    public function labelKey(): string
    {
        return 'app.email_scenario_'.$this->value;
    }

    public function descriptionKey(): string
    {
        return $this->labelKey().'_description';
    }

    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::CustomerClassPassIssued,
            self::CustomerPurchaseFailed,
            self::BookingCreated,
            self::BookingCancelled,
            self::ScheduledClassCancelled,
            self::ScheduledClassRestored,
            self::ClassPassAdjusted,
            self::SaasPaymentPaid,
            self::SaasPaymentFailed,
            self::SaasSubscriptionExpired,
            self::SaasTrialEnding7,
            self::SaasTrialEnding3,
            self::SaasTrialEnding1,
            self::SaasAnnualRenewal,
            self::SaasPriceChange,
            self::SaasGraceExpiry,
            self::SaasCancellation,
            self::SaasReactivation,
            self::SaasTariffChange => true,
            default => false,
        };
    }

    public function lifecycleType(): ?string
    {
        return match ($this) {
            self::SaasTrialEnding7 => 'trial_ending_7',
            self::SaasTrialEnding3 => 'trial_ending_3',
            self::SaasTrialEnding1 => 'trial_ending_1',
            self::SaasAnnualRenewal => 'annual_renewal',
            self::SaasPriceChange => 'price_change',
            self::SaasGraceExpiry => 'grace_expiry',
            self::SaasCancellation => 'cancellation',
            self::SaasReactivation => 'reactivation',
            self::SaasTariffChange => 'tariff_change',
            default => null,
        };
    }

    public static function fromLifecycleType(string $type): self
    {
        foreach (self::cases() as $scenario) {
            if ($scenario->lifecycleType() === $type) {
                return $scenario;
            }
        }

        throw new ValueError("Unsupported email lifecycle scenario [{$type}].");
    }
}
