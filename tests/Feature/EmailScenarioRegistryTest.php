<?php

namespace Tests\Feature;

use App\Enums\EmailScenario;
use App\Support\Mail\EmailScenarioPreviewFactory;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class EmailScenarioRegistryTest extends TestCase
{
    public function test_registry_defines_all_existing_scenarios_with_valid_bilingual_templates(): void
    {
        $this->assertSame([
            'customer_class_pass_issued',
            'customer_purchase_failed',
            'booking_created',
            'booking_cancelled',
            'scheduled_class_cancelled',
            'scheduled_class_restored',
            'class_pass_adjusted',
            'saas_payment_paid',
            'saas_payment_failed',
            'saas_subscription_expired',
            'saas_trial_ending_7',
            'saas_trial_ending_3',
            'saas_trial_ending_1',
            'saas_annual_renewal',
            'saas_price_change',
            'saas_grace_expiry',
            'saas_cancellation',
            'saas_reactivation',
            'saas_tariff_change',
            'sms_credit_low',
            'sms_auto_top_up_failed',
            'sms_outstanding_credit',
            'event_tickets_issued',
            'event_updated',
            'event_cancelled',
            'event_payment_attention',
        ], array_column(EmailScenario::cases(), 'value'));

        foreach (EmailScenario::cases() as $scenario) {
            $this->assertTrue($scenario->defaultEnabled(), $scenario->value);
            $this->assertTrue(View::exists($scenario->contentView()), $scenario->contentView());

            foreach (['en', 'uk'] as $locale) {
                $this->assertTrue(Lang::hasForLocale($scenario->labelKey(), $locale), $scenario->labelKey());
                $this->assertTrue(Lang::hasForLocale($scenario->descriptionKey(), $locale), $scenario->descriptionKey());
                $this->assertTrue(Lang::hasForLocale($scenario->subjectKey(), $locale), $scenario->subjectKey());
                $this->assertTrue(Lang::hasForLocale($scenario->group()->labelKey(), $locale), $scenario->group()->labelKey());
                $this->assertTrue(Lang::hasForLocale($scenario->recipientKind()->labelKey(), $locale), $scenario->recipientKind()->labelKey());
            }
        }
    }

    public function test_preview_payloads_are_deterministic_and_all_scenarios_render_in_both_locales(): void
    {
        $factory = app(EmailScenarioPreviewFactory::class);

        foreach (EmailScenario::cases() as $scenario) {
            $this->assertSame($factory->payload($scenario), $factory->payload($scenario));

            foreach (['en', 'uk'] as $locale) {
                $mail = $factory->mail($scenario)->locale($locale);
                $html = $mail->render();
                $subject = Lang::get(
                    $scenario->subjectKey(),
                    $factory->payload($scenario)['subject_parameters'],
                    $locale,
                );

                $this->assertStringContainsString('Ladna Demo Studio', $html, "{$scenario->value}:{$locale}");
                $this->assertStringNotContainsString(':studio', $subject);
                $this->assertStringNotContainsString(':class', $subject);
                $this->assertStringNotContainsString(':pass', $subject);
            }
        }
    }
}
