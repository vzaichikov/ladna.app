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
        Schema::table('event_orders', function (Blueprint $table) {
            $table->string('buyer_email')->nullable()->change();
            $table->dateTime('terms_accepted_at')->nullable()->change();
            $table->char('terms_hash', 64)->nullable()->change();
            $table->foreignId('issued_by')->nullable()->after('terms_hash')->constrained('users')->nullOnDelete();
            $table->index(['event_id', 'issued_by']);
        });

        Schema::table('event_tickets', function (Blueprint $table) {
            $table->index(['event_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'id']);
        });

        Schema::table('event_orders', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'issued_by']);
            $table->dropConstrainedForeignId('issued_by');
            $table->string('buyer_email')->nullable(false)->change();
            $table->dateTime('terms_accepted_at')->nullable(false)->change();
            $table->char('terms_hash', 64)->nullable(false)->change();
        });
    }
};
