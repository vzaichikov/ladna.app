<?php

namespace App\Http\Middleware;

use App\Models\FestivalEdition;
use App\Models\FestivalEntry;
use App\Support\Festivals\FestivalSaasAccess;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFestivalEditionWritable
{
    public function __construct(private readonly FestivalSaasAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $edition = $this->editionFromRoute($request);
        if ($edition) {
            $this->access->assertEditionWritable($edition);
        }

        return $next($request);
    }

    private function editionFromRoute(Request $request): ?FestivalEdition
    {
        $boundEdition = $request->route('festivalEdition');
        if ($boundEdition instanceof FestivalEdition) {
            return $boundEdition;
        }

        foreach ((array) $request->route()?->parameters() as $parameter) {
            if ($parameter instanceof FestivalEntry) {
                return $parameter->edition()->first();
            }
            if ($parameter instanceof Model && isset($parameter->festival_edition_id)) {
                return FestivalEdition::query()->find($parameter->festival_edition_id);
            }
            if ($parameter instanceof Model && isset($parameter->festival_entry_id)) {
                return FestivalEntry::query()->find($parameter->festival_entry_id)?->edition()->first();
            }
        }

        $editionSlug = $request->route('editionSlug');
        $accountSlug = $request->route('accountSlug');
        if (is_string($editionSlug) && is_string($accountSlug)) {
            return FestivalEdition::query()->whereHas('account', fn ($query) => $query->where('slug', $accountSlug))->where('slug', $editionSlug)->first();
        }

        return null;
    }
}
