<?php

namespace App\Http\Controllers;

use App\Support\ApplicationVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    public function english(): View
    {
        return $this->show('en');
    }

    public function ukrainian(): View
    {
        return $this->show('uk');
    }

    private function show(string $locale): View
    {
        App::setLocale($locale);
        Carbon::setLocale($locale);

        return view('changelog.show', [
            'copy' => config("changelog.copy.$locale"),
            'currentVersion' => ApplicationVersion::current(),
            'locale' => $locale,
            'releases' => collect(config("changelog.releases.$locale", []))
                ->map(function (array $release) use ($locale): array {
                    $release['display_date'] = Carbon::parse($release['date'])
                        ->translatedFormat($locale === 'uk' ? 'j F Y' : 'F j, Y');

                    return $release;
                })
                ->all(),
        ]);
    }
}
