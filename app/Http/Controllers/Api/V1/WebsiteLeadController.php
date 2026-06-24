<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreWebsiteLeadRequest;
use Illuminate\Http\JsonResponse;

class WebsiteLeadController extends Controller
{
    public function __invoke(StoreWebsiteLeadRequest $request): JsonResponse
    {
        $account = $request->attributes->get('account');
        $websiteLead = $account->websiteLeads()->create($request->validated());

        return response()->json([
            'data' => [
                'id' => $websiteLead->id,
                'status' => $websiteLead->status->value,
                'phone' => $websiteLead->phone,
                'name' => $websiteLead->name,
                'source_page' => $websiteLead->source_page,
                'created_at' => $websiteLead->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
