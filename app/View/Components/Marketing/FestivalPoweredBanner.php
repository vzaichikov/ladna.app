<?php

namespace App\View\Components\Marketing;

use App\Support\Festivals\FestivalPoweredBannerSettings;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;

class FestivalPoweredBanner extends Component
{
    public bool $visible;

    public string $href;

    public function __construct(FestivalPoweredBannerSettings $settings, Request $request)
    {
        $this->visible = $settings->visible($request);
        $this->href = app()->isLocale('en') ? route('home.en') : route('home');
    }

    public function shouldRender(): bool
    {
        return $this->visible;
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.marketing.festival-powered-banner');
    }
}
