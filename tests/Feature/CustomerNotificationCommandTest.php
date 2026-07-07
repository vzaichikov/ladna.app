<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerNotificationCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_send_command_reports_empty_queue(): void
    {
        $this->artisan('customer-notifications:send')
            ->expectsOutput(__('app.customer_notifications_send_command_result', [
                'processed' => 0,
                'sent' => 0,
                'retried' => 0,
                'failed' => 0,
                'cancelled' => 0,
                'skipped' => 0,
                'rescheduled' => 0,
            ]))
            ->assertSuccessful();
    }

    public function test_fill_command_reports_empty_scan(): void
    {
        $this->artisan('customer-notifications:fill --lookahead-hours=24 --limit=10')
            ->expectsOutput(__('app.customer_notifications_fill_command_result', [
                'processed' => 0,
                'queued' => 0,
                'cancelled' => 0,
                'skipped' => 0,
            ]))
            ->assertSuccessful();
    }
}
