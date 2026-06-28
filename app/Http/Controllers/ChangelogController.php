<?php

namespace App\Http\Controllers;

use App\Support\ApplicationVersion;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class ChangelogController extends Controller
{
    private const ReleasesPerPage = 20;

    public function english(Request $request): View
    {
        return $this->show('en', $request);
    }

    public function ukrainian(Request $request): View
    {
        return $this->show('uk', $request);
    }

    private function show(string $locale, Request $request): View
    {
        App::setLocale($locale);
        Carbon::setLocale($locale);
        $releases = $this->releases($locale);

        return view('changelog.show', [
            'copy' => config("changelog.copy.$locale"),
            'currentVersion' => ApplicationVersion::current(),
            'locale' => $locale,
            'releases' => $this->paginatedReleases($releases, $locale, $request),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function releases(string $locale): Collection
    {
        return collect(config("changelog.releases.$locale", []))
            ->map(function (array $release) use ($locale): array {
                $release['display_date'] = Carbon::parse($release['date'])
                    ->translatedFormat($locale === 'uk' ? 'j F Y' : 'F j, Y');

                return $release;
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $releases
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatedReleases(Collection $releases, string $locale, Request $request): LengthAwarePaginator
    {
        $currentPage = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $releases->forPage($currentPage, self::ReleasesPerPage)->values(),
            $releases->count(),
            self::ReleasesPerPage,
            $currentPage,
            [
                'path' => route($locale === 'uk' ? 'changelog.ua' : 'changelog.en'),
            ],
        );
    }
}
