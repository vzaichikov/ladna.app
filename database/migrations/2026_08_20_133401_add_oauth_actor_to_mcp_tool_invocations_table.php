<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table): void {
            $table->foreignId('mcp_oauth_connection_id')->nullable()->after('account_api_token_id')->constrained()->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->after('mcp_oauth_connection_id')->constrained('users')->nullOnDelete();
            $table->string('actor_role')->nullable()->after('actor_user_id');
            $table->string('actor_name')->nullable()->after('actor_role');
            $table->string('actor_email')->nullable()->after('actor_name');
            $table->string('credential_type')->nullable()->after('actor_email');
            $table->string('oauth_access_token_id', 100)->nullable()->after('credential_type');
            $table->uuid('oauth_client_id')->nullable()->after('oauth_access_token_id');

            $table->index(['mcp_oauth_connection_id', 'started_at'], 'mcp_invocations_oauth_connection_index');
            $table->index(['actor_user_id', 'started_at'], 'mcp_invocations_actor_user_index');
            $table->index(['oauth_client_id', 'started_at'], 'mcp_invocations_oauth_client_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table): void {
            $table->dropIndex('mcp_invocations_oauth_connection_index');
            $table->dropIndex('mcp_invocations_actor_user_index');
            $table->dropIndex('mcp_invocations_oauth_client_index');
            $table->dropConstrainedForeignId('mcp_oauth_connection_id');
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropColumn(['actor_role', 'actor_name', 'actor_email', 'credential_type', 'oauth_access_token_id', 'oauth_client_id']);
        });
    }
};
