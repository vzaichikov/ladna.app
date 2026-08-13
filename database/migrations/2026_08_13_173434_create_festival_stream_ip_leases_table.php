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
        Schema::create('festival_stream_ip_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_stream_entitlement_id')->constrained()->cascadeOnDelete();
            $table->char('ip_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['festival_stream_entitlement_id', 'ip_hash'], 'festival_stream_lease_ip_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_stream_ip_leases');
    }
};
