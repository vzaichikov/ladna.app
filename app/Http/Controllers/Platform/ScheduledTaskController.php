<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\ScheduledTaskRegistry;
use Illuminate\View\View;

class ScheduledTaskController extends Controller
{
    public function __invoke(ScheduledTaskRegistry $scheduledTasks): View
    {
        return view('platform.scheduled-tasks.index', [
            'tasks' => $scheduledTasks->tasks(),
        ]);
    }
}
