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
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('support_phone_url', 2048)->nullable()->after('support_whatsapp_url');
            $table->string('support_secondary_phone_url', 2048)->nullable()->after('support_phone_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['support_phone_url', 'support_secondary_phone_url']);
        });
    }
};
