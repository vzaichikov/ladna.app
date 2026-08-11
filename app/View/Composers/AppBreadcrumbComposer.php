<?php

namespace App\View\Composers;

use App\Support\Breadcrumbs\AppBreadcrumbs;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AppBreadcrumbComposer
{
    public function __construct(
        private readonly Request $request,
        private readonly AppBreadcrumbs $breadcrumbs,
    ) {}

    public function compose(View $view): void
    {
        $view->with('breadcrumbs', $this->breadcrumbs->resolve($this->request));
    }
}
