<?php

namespace App\Http\Controllers;

use App\Support\Mcp\McpToolDocumentationCatalog;
use App\Support\OpenApi\LadnaOpenApiSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiDocumentationController extends Controller
{
    public function show(
        Request $request,
        LadnaOpenApiSpec $openApiSpec,
        McpToolDocumentationCatalog $toolDocumentationCatalog,
    ): View {
        $activeTab = $this->activeTab($request);
        $spec = $openApiSpec->toArray();

        return view('api-docs.show', [
            'activeTab' => $activeTab,
            'paths' => $this->pathsForTab($spec['paths'], $activeTab),
            'examples' => $this->examplesForTab($openApiSpec->examples(), $activeTab),
            'openApiUrl' => route('api-docs.openapi'),
            'mcpUrl' => url('/mcp/ladna-studio/your-studio'),
            'mcpServiceUrl' => route('mcp.ladna-studio'),
            'mcpToolGroups' => $toolDocumentationCatalog->groups(),
        ]);
    }

    public function openApi(LadnaOpenApiSpec $openApiSpec): JsonResponse
    {
        return response()->json($openApiSpec->toArray());
    }

    private function activeTab(Request $request): string
    {
        $requestedTab = $request->query('tab');
        $allowedTabs = ['public', 'restricted', 'mcp', 'connect'];

        return is_string($requestedTab) && in_array($requestedTab, $allowedTabs, true)
            ? $requestedTab
            : 'public';
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return array<string, mixed>
     */
    private function pathsForTab(array $paths, string $activeTab): array
    {
        return collect($paths)
            ->filter(fn (mixed $operations, string $path): bool => $this->pathBelongsToTab($path, $activeTab))
            ->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $examples
     * @return array<string, array<string, mixed>>
     */
    private function examplesForTab(array $examples, string $activeTab): array
    {
        return collect($examples)
            ->filter(fn (array $example): bool => $this->pathBelongsToTab($example['path'], $activeTab))
            ->all();
    }

    private function pathBelongsToTab(string $path, string $activeTab): bool
    {
        $isPublicApi = str_starts_with($path, '/api/v1/public/');
        $isMcp = str_starts_with($path, '/mcp/');

        return match ($activeTab) {
            'public' => $isPublicApi,
            'restricted' => ! $isPublicApi && ! $isMcp,
            'mcp' => $isMcp,
            default => false,
        };
    }
}
