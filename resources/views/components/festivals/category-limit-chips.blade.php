@props([
    'category',
    'surface' => 'muted',
])

@php
    $limits = app(\App\Support\Festivals\FestivalCategoryLimitsPresenter::class)->present($category);
    $chipClass = $surface === 'white'
        ? 'rounded-full bg-white px-3 py-1.5'
        : 'rounded-full bg-slate-100 px-3 py-1.5';
@endphp

@foreach ([
    ['label' => __('app.festival_roster'), 'value' => $limits['participants']],
    ['label' => __('app.festival_age_limits'), 'value' => $limits['age']],
    ['label' => __('app.festival_performance_duration'), 'value' => $limits['duration']],
] as $limit)
    @if ($limit['value'] !== null)
        <div class="{{ $chipClass }}">
            <dt class="sr-only">{{ $limit['label'] }}</dt>
            <dd>{{ $limit['value'] }}</dd>
        </div>
    @endif
@endforeach
