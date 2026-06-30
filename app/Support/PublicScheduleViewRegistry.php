<?php

namespace App\Support;

use App\Enums\PublicScheduleView;

class PublicScheduleViewRegistry
{
    /**
     * @return array<int, array{view: PublicScheduleView, value: string, label_key: string, copy_key: string, preview_key: string}>
     */
    public static function options(): array
    {
        return collect(PublicScheduleView::cases())
            ->map(fn (PublicScheduleView $view): array => [
                'view' => $view,
                'value' => $view->value(),
                'label_key' => $view->labelKey(),
                'copy_key' => $view->copyKey(),
                'preview_key' => 'app.public_schedule_view_'.$view->value().'_preview',
            ])
            ->all();
    }
}
