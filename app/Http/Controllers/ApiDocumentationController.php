<?php

namespace App\Http\Controllers;

use App\Support\OpenApi\LadnaOpenApiSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    public function show(LadnaOpenApiSpec $openApiSpec): View
    {
        return view('api-docs.show', [
            'spec' => $openApiSpec->toArray(),
            'examples' => $openApiSpec->examples(),
            'openApiUrl' => route('api-docs.openapi'),
        ]);
    }

    public function openApi(LadnaOpenApiSpec $openApiSpec): JsonResponse
    {
        return response()->json($openApiSpec->toArray());
    }
}
