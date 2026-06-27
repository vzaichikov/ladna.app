@extends('layouts.app')

@section('title', __('app.scheduled_tasks').' - '.__('app.app_name'))

@section('content')
    @php
        $formatDate = fn ($date): string => $date
            ? $date->timezone(config('app.timezone'))->format('d.m.Y H:i')
            : __('app.not_set');
    @endphp

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.scheduled_tasks') }}</h1>
            <p class="crm-page-copy">{{ __('app.scheduled_tasks_copy') }}</p>
        </div>
    </div>

    <x-ui.panel padding="none" class="mt-6 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[980px] text-left text-sm">
                <thead class="bg-stone-50 text-xs font-semibold uppercase text-slate-500">
                    <tr>
                        <th class="px-5 py-3">{{ __('app.command') }}</th>
                        <th class="px-5 py-3">{{ __('app.schedule') }}</th>
                        <th class="px-5 py-3">{{ __('app.status') }}</th>
                        <th class="px-5 py-3">{{ __('app.last_run') }}</th>
                        <th class="px-5 py-3">{{ __('app.finished_at') }}</th>
                        <th class="px-5 py-3">{{ __('app.next_run') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($tasks as $task)
                        @php
                            $statusClass = match ($task['status']) {
                                \App\Models\ScheduledTaskStatus::StatusSucceeded => 'crm-status-active',
                                \App\Models\ScheduledTaskStatus::StatusFailed => 'crm-status-danger',
                                \App\Models\ScheduledTaskStatus::StatusRunning => 'crm-status-scheduled',
                                default => 'crm-status-muted',
                            };
                        @endphp
                        <tr class="align-top">
                            <td class="px-5 py-4">
                                <div class="font-semibold text-slate-950">{{ $task['command'] }}</div>
                                <div class="mt-1 text-sm leading-6 text-slate-500">{{ __('app.'.$task['description_key']) }}</div>
                                @if ($task['last_output'])
                                    <details class="mt-3">
                                        <summary class="cursor-pointer text-xs font-semibold uppercase text-slate-500">{{ __('app.last_output') }}</summary>
                                        <pre class="mt-2 max-h-40 overflow-auto rounded-lg bg-slate-950 p-3 text-xs leading-5 text-white">{{ $task['last_output'] }}</pre>
                                    </details>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <div class="font-mono text-sm text-slate-950">{{ $task['expression'] }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ __('app.'.$task['frequency_key']) }}</div>
                                @if ($task['overlap_minutes'])
                                    <div class="mt-1 text-xs text-slate-400">{{ __('app.overlap_lock_minutes', ['minutes' => $task['overlap_minutes']]) }}</div>
                                @else
                                    <div class="mt-1 text-xs text-slate-400">{{ __('app.overlap_lock_default') }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4">
                                <span class="{{ $statusClass }}">{{ __('app.scheduled_task_status_'.$task['status']) }}</span>
                                @if ($task['last_exit_code'] !== null)
                                    <div class="mt-2 text-xs text-slate-500">{{ __('app.exit_code') }}: {{ $task['last_exit_code'] }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-slate-700">{{ $formatDate($task['last_started_at']) }}</td>
                            <td class="px-5 py-4 text-slate-700">{{ $formatDate($task['last_finished_at']) }}</td>
                            <td class="px-5 py-4">
                                <div class="text-slate-700">{{ $formatDate($task['next_due_at']) }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $task['next_due_at']->diffForHumans() }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.panel>
@endsection
