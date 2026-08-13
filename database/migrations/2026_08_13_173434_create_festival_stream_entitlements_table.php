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
        if (! Schema::hasTable('festival_stream_entitlements')) {
            Schema::create('festival_stream_entitlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_online_stream_id')->constrained()->cascadeOnDelete();
                $table->foreignId('festival_ticket_id')->unique()->constrained()->cascadeOnDelete();
                $table->foreignId('festival_portal_user_id')->constrained()->restrictOnDelete();
                $table->timestamps();
                $table->unique(['festival_online_stream_id', 'festival_portal_user_id'], 'festival_stream_guest_unique');
                $table->index(['account_id', 'festival_portal_user_id'], 'festival_stream_entitlement_account_user_idx');
            });

            return;
        }

        $indexNames = collect(Schema::getIndexes('festival_stream_entitlements'))->pluck('name');
        if (! $indexNames->contains('festival_stream_entitlement_account_user_idx')) {
            Schema::table('festival_stream_entitlements', function (Blueprint $table) {
                $table->index(['account_id', 'festival_portal_user_id'], 'festival_stream_entitlement_account_user_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_stream_entitlements');
    }
};
