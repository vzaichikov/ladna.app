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
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->after('owner_type')->constrained()->cascadeOnDelete();
            $table->index(['account_id', 'revoked'], 'oauth_clients_account_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dropIndex('oauth_clients_account_active_index');
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
