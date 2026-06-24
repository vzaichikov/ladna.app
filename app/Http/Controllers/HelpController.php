<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function index(): View
    {
        $this->setHelpLocale();

        return view('help.index', [
            'copy' => config('help.copy'),
            'pages' => $this->pages(),
            'updatedAt' => $this->updatedAt(),
        ]);
    }

    public function show(string $slug): View
    {
        $this->setHelpLocale();

        $pages = $this->pages();
        $page = $pages[$slug] ?? null;

        abort_unless(is_array($page), 404);

        return view('help.show', [
            'copy' => config('help.copy'),
            'page' => $page,
            'pages' => $pages,
            'slug' => $slug,
            'updatedAt' => $this->updatedAt(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function pages(): array
    {
        $pages = config('help.pages', []);

        return is_array($pages) ? $pages : [];
    }

    private function updatedAt(): string
    {
        return Carbon::parse(config('help.updated_at'))->translatedFormat('j F Y');
    }

    private function setHelpLocale(): void
    {
        App::setLocale('uk');
        Carbon::setLocale('uk');
    }
}
