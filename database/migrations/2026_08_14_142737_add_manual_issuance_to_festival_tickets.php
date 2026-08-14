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
        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->string('source')->default('checkout')->after('festival_portal_user_id');
            $table->foreignId('issued_by_user_id')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable()->after('issued_by_user_id');
            $table->index(['festival_edition_id', 'source', 'id'], 'festival_ticket_orders_edition_source_idx');
            $table->timestamp('terms_accepted_at')->nullable()->change();
            $table->char('terms_hash', 64)->nullable()->change();
        });

        Schema::table('festival_tickets', function (Blueprint $table) {
            $table->string('holder_name')->nullable()->after('festival_admission_type_id');
            $table->foreignId('festival_participant_id')->nullable()->after('holder_name')->constrained('festival_participants')->restrictOnDelete();
            $table->foreignId('festival_judge_assignment_id')->nullable()->after('festival_participant_id')->constrained('festival_judge_assignments')->restrictOnDelete();
            $table->string('automation_key')->nullable()->after('festival_judge_assignment_id');
            $table->unique(['festival_edition_id', 'festival_participant_id'], 'festival_tickets_edition_participant_unique');
            $table->unique(['festival_edition_id', 'festival_judge_assignment_id'], 'festival_tickets_edition_judge_unique');
            $table->unique(['festival_edition_id', 'automation_key'], 'festival_tickets_edition_automation_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('festival_ticket_orders')->whereNull('terms_accepted_at')->orWhereNull('terms_hash')->exists()) {
            throw new RuntimeException('Manual Festival ticket orders must be removed before rolling back this migration.');
        }

        Schema::table('festival_tickets', function (Blueprint $table) {
            $table->dropUnique('festival_tickets_edition_participant_unique');
            $table->dropUnique('festival_tickets_edition_judge_unique');
            $table->dropUnique('festival_tickets_edition_automation_unique');
            $table->dropConstrainedForeignId('festival_participant_id');
            $table->dropConstrainedForeignId('festival_judge_assignment_id');
            $table->dropColumn(['holder_name', 'automation_key']);
        });

        Schema::table('festival_ticket_orders', function (Blueprint $table) {
            $table->dropIndex('festival_ticket_orders_edition_source_idx');
            $table->dropConstrainedForeignId('issued_by_user_id');
            $table->dropColumn(['source', 'issued_at']);
            $table->timestamp('terms_accepted_at')->nullable(false)->change();
            $table->char('terms_hash', 64)->nullable(false)->change();
        });
    }
};
