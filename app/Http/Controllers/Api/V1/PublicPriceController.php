<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\BuildPublicPriceList;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClassPassPlanResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicPriceController extends Controller
{
    public function __invoke(
        Request $request,
        string $accountSlug,
        string $locationSlug,
        BuildPublicPriceList $buildPublicPriceList,
    ): JsonResponse {
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
                    'plans' => $section['plans']->map(fn ($classPassPlan): array => [
                        ...(new ClassPassPlanResource($classPassPlan))->resolve($request),
                        'checkout_url' => route('public.class-pass-plans.checkout', [
                            $account->slug,
                            $location->slug,
                            $classPassPlan->slug,
                        ]),
                    ])->values(),
                ])->values(),
            ])
            ->values();

        return response()->json(['data' => $groups]);
    }
}
