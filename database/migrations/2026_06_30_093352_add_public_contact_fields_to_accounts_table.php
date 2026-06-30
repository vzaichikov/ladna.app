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
            $table->string('studio_slogan')->nullable()->after('brand_color');
            $table->string('support_instagram_url', 2048)->nullable()->after('tax_id');
            $table->string('support_telegram_url', 2048)->nullable()->after('support_instagram_url');
            $table->string('support_viber_url', 2048)->nullable()->after('support_telegram_url');
            $table->string('support_whatsapp_url', 2048)->nullable()->after('support_viber_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'studio_slogan',
                'support_instagram_url',
                'support_telegram_url',
                'support_viber_url',
                'support_whatsapp_url',
            ]);
        });
    }
};
