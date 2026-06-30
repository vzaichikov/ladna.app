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
            $table->string('public_schedule_view')->default('classic')->after('class_pass_cancellation_rules');
            $table->boolean('allow_guest_public_booking')->default(false)->after('public_schedule_view');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['public_schedule_view', 'allow_guest_public_booking']);
        });
    }
};
