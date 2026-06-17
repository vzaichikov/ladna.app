<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduledClassResource;
use App\Models\Account;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublicScheduleController extends Controller
{
    public function schedule(string $accountSlug, string $locationSlug): AnonymousResourceCollection
    {
        return $this->classes($accountSlug, $locationSlug);
    }

    public function classes(string $accountSlug, string $locationSlug): AnonymousResourceCollection
    {
        $account = Account::active()->where('slug', $accountSlug)->firstOrFail();
        $location = $account->locations()
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $classes = $location->scheduledClasses()
            ->publicUpcoming()
            ->with(['account', 'location', 'room', 'classType.activityDirection', 'trainer'])
            ->limit(30)
            ->get();

        return ScheduledClassResource::collection($classes);
    }
}
