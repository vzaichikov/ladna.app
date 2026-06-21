<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class LegalPageController extends Controller
{
    public function termsEnglish(): View
    {
        return $this->show('terms', 'en');
    }

    public function termsUkrainian(): View
    {
        return $this->show('terms', 'uk');
    }

    public function privacyEnglish(): View
    {
        return $this->show('privacy', 'en');
    }

    public function privacyUkrainian(): View
    {
        return $this->show('privacy', 'uk');
    }

    private function show(string $page, string $locale): View
    {
        App::setLocale($locale);
        Carbon::setLocale($locale);

        $copy = config("legal.pages.$page.$locale");

        abort_unless(is_array($copy), 404);

        return view('legal.show', [
            'copy' => $copy,
            'locale' => $locale,
            'page' => $page,
            'updatedAt' => Carbon::parse(config('legal.updated_at'))
                ->translatedFormat($locale === 'uk' ? 'j F Y' : 'F j, Y'),
        ]);
    }
}
