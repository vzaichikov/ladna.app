<?php

namespace Tests\Feature;

use App\Models\ScheduledTaskStatus;
use App\Models\User;
use App\Support\ScheduledTaskRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduledTaskStatusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_platform_admin_can_view_scheduled_tasks(): void
    {
        $platformAdmin = User::factory()->platformAdmin()->create();
        ScheduledTaskStatus::query()->updateOrCreate(
            ['task_key' => 'class_passes_normalize'],
            [
                'command' => 'class-passes:normalize',
                'expression' => '*/15 * * * *',
                'status' => ScheduledTaskStatus::StatusSucceeded,
                'last_started_at' => now()->subMinutes(16),
                'last_finished_at' => now()->subMinutes(15),
                'last_exit_code' => 0,
                'last_output' => 'Normalized 12 passes.',
            ],
        );

        $this->actingAs($platformAdmin)
            ->get(route('platform.scheduled-tasks.index'))
            ->assertOk()
            ->assertSee(__('app.scheduled_tasks'))
            ->assertSee(route('platform.scheduled-tasks.index'), false)
            ->assertSee('schedule:generate')
            ->assertSee('class-passes:normalize')
            ->assertSee('billing:reconcile')
            ->assertSee('account-activity-logs:prune')
            ->assertSee('*/15 * * * *')
            ->assertSee(__('app.scheduled_task_status_succeeded'))
            ->assertSee('Normalized 12 passes.');
    }

    public function test_studio_owner_cannot_view_scheduled_tasks(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('platform.scheduled-tasks.index'))
            ->assertForbidden();
    }

    public function test_registry_records_started_and_finished_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-27 12:00:00', config('app.timezone')));

        $registry = app(ScheduledTaskRegistry::class);
        $definition = collect($registry->definitions())
            ->firstWhere('key', 'billing_reconcile');

        $this->assertNotNull($definition);

        $registry->markStarted($definition);

        $status = ScheduledTaskStatus::query()->where('task_key', 'billing_reconcile')->firstOrFail();

        $this->assertSame(ScheduledTaskStatus::StatusRunning, $status->status);
        $this->assertNotNull($status->last_started_at);
        $this->assertNull($status->last_finished_at);

        Carbon::setTestNow(Carbon::parse('2026-06-27 12:01:00', config('app.timezone')));

        $registry->markFinished($definition, ScheduledTaskStatus::StatusFailed, 1, str("Failed\x1b[31m output"));

        $status->refresh();

        $this->assertSame(ScheduledTaskStatus::StatusFailed, $status->status);
        $this->assertSame(1, $status->last_exit_code);
        $this->assertSame('Failed output', $status->last_output);
        $this->assertNotNull($status->last_finished_at);

        Carbon::setTestNow();
    }

    public function test_people_counter_capture_runs_every_seven_minutes(): void
    {
        $definition = collect(app(ScheduledTaskRegistry::class)->definitions())
            ->firstWhere('key', 'people_counter_capture');

        $this->assertNotNull($definition);
        $this->assertSame('*/7 * * * *', $definition['expression']);
        $this->assertSame('scheduled_task_frequency_every_seven_minutes', $definition['frequency_key']);
    }
}
