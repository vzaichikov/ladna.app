<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\BuildPublicPriceList;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClassPassPlanResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;

class PublicPriceController extends Controller
{
    public function __invoke(string $accountSlug, string $locationSlug, BuildPublicPriceList $buildPublicPriceList): JsonResponse
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $groups = $buildPublicPriceList->execute($account, $location)
            ->map(fn (array $group): array => [
                'key' => $group['key'],
                'schedule_kind' => $group['key'],
                'title' => $group['title'],
                'sections' => $group['sections']->map(fn (array $section): array => [
                    'key' => $section['key'],
                    'title' => $section['title'],
                    'plans' => ClassPassPlanResource::collection($section['plans'])->resolve(),
                ])->values(),
            ])
            ->values();

        return response()->json(['data' => $groups]);
    }
}
