<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account_api_tokens', function (Blueprint $table) {
            $table->json('abilities')->nullable()->after('last_four');
        });

        DB::table('account_api_tokens')
            ->whereNull('abilities')
            ->update(['abilities' => json_encode(['website_leads:create'], JSON_THROW_ON_ERROR)]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_api_tokens', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });
    }
};
