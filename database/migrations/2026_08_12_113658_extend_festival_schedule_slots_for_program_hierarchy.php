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
        Schema::table('festival_schedule_slots', function (Blueprint $table) {
            $table->dropForeign(['festival_stage_id']);
            $table->foreignId('festival_entry_id')->nullable()->change();
            $table->foreignId('festival_category_id')->nullable()->after('festival_entry_id')->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->after('festival_category_id')->constrained('festival_schedule_slots')->nullOnDelete();
            $table->string('name')->nullable()->after('type');
            $table->timestamp('starts_at')->nullable()->change();
            $table->timestamp('ends_at')->nullable()->change();
            $table->unsignedInteger('sort_order')->default(0)->after('ends_at')->index();
            $table->foreign('festival_stage_id')->references('id')->on('festival_stages')->restrictOnDelete();
            $table->index(['festival_stage_id', 'parent_id', 'sort_order', 'id'], 'festival_slots_tree_order_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('festival_schedule_slots', function (Blueprint $table) {
            $table->dropIndex('festival_slots_tree_order_idx');
            $table->dropIndex(['sort_order']);
            $table->dropForeign(['festival_stage_id']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('festival_category_id');
            $table->dropColumn(['name', 'sort_order']);
            $table->foreignId('festival_entry_id')->nullable(false)->change();
            $table->timestamp('starts_at')->nullable(false)->change();
            $table->timestamp('ends_at')->nullable(false)->change();
            $table->foreign('festival_stage_id')->references('id')->on('festival_stages')->cascadeOnDelete();
        });
    }
};
