<?php

namespace App\Support\Mail;

use App\Enums\EmailScenario;
use App\Mail\TransactionalMail;

class EmailScenarioPreviewFactory
{
    /**
     * @return array{data: array<string, mixed>, subject_parameters: array<string, string>}
     */
    public function payload(EmailScenario $scenario): array
    {
        $data = [
            'account_name' => 'Ladna Demo Studio',
            'account_logo_url' => null,
            'account_brand_color' => '#6d28d9',
            'support_url' => 'https://ladna.app',
            'recipient_name' => 'Anna',
            'action_url' => 'https://ladna.app/app',
            'class_title' => 'Pole Beginner',
            'class_time' => '2026-08-14 18:30 - 19:30',
            'location_name' => 'Podil',
            'room_name' => 'Purple room',
            'trainer_name' => 'Olena',
            'pass_name' => '8 classes',
            'pass_code' => 'LADNA-2026',
            'sessions_count' => '8',
            'remaining_sessions_count' => '8',
            'expires_at' => '2026-10-14',
            'usable_until_at' => '2026-10-14',
            'amount' => '2 400 UAH',
            'status' => 'Payment failed',
            'failure_reason' => 'The bank declined the payment',
            'sessions_delta' => '+2',
            'previous_sessions_count' => '6',
            'new_sessions_count' => '8',
            'days_delta' => '+14',
            'previous_validity_days' => '30',
            'new_validity_days' => '44',
            'previous_status' => 'Active',
            'new_status' => 'Active',
            'freeze_started_at' => '2026-08-01 09:00',
            'freeze_finished_at' => '2026-08-14 09:00',
            'freeze_days_count' => '14',
            'reason' => 'Medical pause',
            'plan_name' => 'Growth',
            'locations' => 2,
            'period' => '2026-08-01 - 2026-08-31',
            'period_ends_at' => '2026-08-31',
            'notice' => $scenario->value,
            'balance' => '45 UAH',
            'outstanding' => $scenario === EmailScenario::SmsOutstandingCredit ? '12 UAH' : null,
            'notice_type' => $scenario->lifecycleType(),
            'notice_parameters' => [
                'date' => '2026-08-31',
                'amount' => '3 600 UAH',
                'locations' => 2,
                'plan' => 'Growth',
            ],
            'event_title' => 'Summer yoga morning',
            'event_time' => '2026-08-14 09:00 - 11:00',
            'event_venue' => 'Podil · Purple room',
            'tickets' => [
                [
                    'type' => 'General admission',
                    'code' => 'EVT-DEMO-0001',
                    'qr_data' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                    'qr_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                ],
            ],
        ];

        return [
            'data' => $data,
            'subject_parameters' => [
                'studio' => $data['account_name'],
                'class' => $data['class_title'],
                'pass' => $data['pass_name'],
                'event' => $data['event_title'],
            ],
        ];
    }

    public function mail(EmailScenario $scenario): TransactionalMail
    {
        $payload = $this->payload($scenario);

        return new TransactionalMail(
            subjectKey: $scenario->subjectKey(),
            contentView: $scenario->contentView(),
            data: $payload['data'],
            subjectParameters: $payload['subject_parameters'],
        );
    }
}
