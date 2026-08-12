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
        Schema::table('festival_charge_definitions', function (Blueprint $table): void {
            $table->string('pricing_mode', 20)->default('fixed')->after('amount_cents');
            $table->unsignedSmallInteger('included_members')->nullable()->after('pricing_mode');
            $table->unsignedBigInteger('additional_member_amount_cents')->nullable()->after('included_members');
            $table->string('due_policy', 30)->default('fixed')->after('due_at');
            $table->unsignedSmallInteger('due_days_after_approval')->nullable()->after('due_policy');
            $table->timestamp('due_hard_cap_at')->nullable()->after('due_days_after_approval');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_charge_definitions', function (Blueprint $table): void {
            $table->dropColumn([
                'pricing_mode',
                'included_members',
                'additional_member_amount_cents',
                'due_policy',
                'due_days_after_approval',
                'due_hard_cap_at',
            ]);
        });
    }
};
