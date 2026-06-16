@extends('layouts.app')

@section('title', __('app.class_types').' - '.$account->name)

@section('content')
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="crm-page-title">{{ __('app.class_types') }}</h1>
            <p class="crm-page-copy">{{ $account->name }}</p>
        </div>
        <a href="{{ route('dashboard.accounts.class-types.create', $account) }}" class="inline-flex items-center justify-center rounded-lg bg-violet-crm-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-crm-700">{{ __('app.create_class_type') }}</a>
    </div>

    <div class="mt-8 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-crm">
        @forelse ($classTypes as $classType)
            <div class="grid gap-3 border-b border-slate-100 px-5 py-4 last:border-b-0 md:grid-cols-[1.2fr_1fr_1fr_auto] md:items-center">
                <div>
                    <div class="font-semibold">{{ $classType->name }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $classType->activityDirection?->name ?? __('app.direction') }}</div>
                </div>
                <div class="text-sm text-slate-500">{{ __('app.'.$classType->schedule_kind->value) }}</div>
                <div class="text-sm text-slate-500">{{ $classType->default_duration_minutes }} {{ __('app.minutes') }} · {{ __('app.capacity') }} {{ $classType->default_capacity ?? 'TBA' }}</div>
                <div class="flex gap-2">
                    <a href="{{ route('dashboard.accounts.class-types.edit', [$account, $classType]) }}" class="inline-flex items-center justify-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 shadow-xs transition hover:border-slate-300 hover:bg-slate-50">{{ __('app.edit') }}</a>
                    <form method="POST" action="{{ route('dashboard.accounts.class-types.destroy', [$account, $classType]) }}" data-confirm-delete>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">{{ __('app.delete') }}</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="p-8 text-center text-slate-500">{{ __('app.no_class_types') }}</div>
        @endforelse
    </div>
@endsection
