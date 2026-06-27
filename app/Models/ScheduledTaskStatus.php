<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['task_key', 'command', 'expression', 'status', 'last_started_at', 'last_finished_at', 'last_exit_code', 'last_output'])]
class ScheduledTaskStatus extends Model
{
    public const StatusNeverRun = 'never_run';

    public const StatusRunning = 'running';

    public const StatusSucceeded = 'succeeded';

    public const StatusFailed = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_finished_at' => 'datetime',
        ];
    }
}
