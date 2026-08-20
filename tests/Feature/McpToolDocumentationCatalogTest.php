<?php

namespace Tests\Feature;

use App\Mcp\Servers\LadnaStudioServer;
use App\Models\Account;
use App\Models\User;
use App\Support\Mcp\McpOAuthToolAccessPolicy;
use App\Support\Mcp\McpToolDocumentationCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class McpToolDocumentationCatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_catalog_documents_every_registered_tool_once(): void
    {
        $catalogClasses = app(McpToolDocumentationCatalog::class)->toolClasses();
        $registeredClasses = LadnaStudioServer::TOOL_CLASSES;

        sort($catalogClasses);
        sort($registeredClasses);

        $this->assertSame($registeredClasses, $catalogClasses);
        $this->assertCount(count(array_unique($catalogClasses)), $catalogClasses);
    }

    public function test_every_registered_tool_is_marked_read_only_and_idempotent(): void
    {
        foreach (LadnaStudioServer::TOOL_CLASSES as $toolClass) {
            $tool = app($toolClass)->toArray();

            $this->assertTrue($tool['annotations']['readOnlyHint'] ?? false, $toolClass);
            $this->assertTrue($tool['annotations']['idempotentHint'] ?? false, $toolClass);
            $this->assertFalse($tool['annotations']['openWorldHint'] ?? true, $toolClass);
        }
    }

    public function test_every_registered_tool_has_an_explicit_oauth_access_policy(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create();
        $account->addOwner($owner);
        $policy = app(McpOAuthToolAccessPolicy::class);

        foreach (LadnaStudioServer::TOOL_CLASSES as $toolClass) {
            $this->assertTrue($policy->canUseTool($account, $owner, $toolClass), $toolClass);
        }

        $this->assertFalse($policy->canUseTool($account, $owner, self::class));
    }
}
