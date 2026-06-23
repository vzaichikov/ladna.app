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
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->string('schedule_kind')->default('group_class')->after('slug');
            $table->index(['account_id', 'schedule_kind', 'is_active', 'sort_order'], 'class_pass_plans_account_kind_active_sort_index');
        });

        $scheduleKindPriority = ['group_class', 'private_lesson', 'room_rental'];

        DB::table('class_pass_plan_class_type')
            ->join('class_types', 'class_types.id', '=', 'class_pass_plan_class_type.class_type_id')
            ->select([
                'class_pass_plan_class_type.class_pass_plan_id',
                'class_types.schedule_kind',
            ])
            ->orderBy('class_pass_plan_class_type.class_pass_plan_id')
            ->get()
            ->groupBy('class_pass_plan_id')
            ->each(function ($rows, int $classPassPlanId) use ($scheduleKindPriority): void {
                $scheduleKind = collect($scheduleKindPriority)
                    ->first(fn (string $value): bool => $rows->contains('schedule_kind', $value))
                    ?? 'group_class';

                DB::table('class_pass_plans')
                    ->where('id', $classPassPlanId)
                    ->update(['schedule_kind' => $scheduleKind]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_pass_plans', function (Blueprint $table) {
            $table->dropIndex('class_pass_plans_account_kind_active_sort_index');
            $table->dropColumn('schedule_kind');
        });
    }
};
