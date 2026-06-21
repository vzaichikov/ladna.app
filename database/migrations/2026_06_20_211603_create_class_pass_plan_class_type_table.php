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
        Schema::create('class_pass_plan_class_type', function (Blueprint $table) {
            $table->foreignId('class_pass_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['class_pass_plan_id', 'class_type_id'], 'class_pass_plan_class_type_primary');
            $table->index('class_type_id', 'class_pass_plan_class_type_type_index');
        });

        DB::table('class_pass_plan_activity_direction')
            ->join('class_types', 'class_types.activity_direction_id', '=', 'class_pass_plan_activity_direction.activity_direction_id')
            ->join('class_pass_plans', 'class_pass_plans.id', '=', 'class_pass_plan_activity_direction.class_pass_plan_id')
            ->whereColumn('class_types.account_id', 'class_pass_plans.account_id')
            ->select([
                'class_pass_plan_activity_direction.class_pass_plan_id',
                'class_types.id as class_type_id',
                DB::raw('CURRENT_TIMESTAMP as created_at'),
                DB::raw('CURRENT_TIMESTAMP as updated_at'),
            ])
            ->distinct()
            ->orderBy('class_pass_plan_activity_direction.class_pass_plan_id')
            ->chunk(500, function ($rows): void {
                DB::table('class_pass_plan_class_type')->insertOrIgnore(
                    $rows->map(fn ($row): array => (array) $row)->all()
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_pass_plan_class_type');
    }
};
