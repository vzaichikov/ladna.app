<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('accounts')
            ->orderBy('id')
            ->get()
            ->each(function (object $account) use ($now): void {
                $trainerTypeId = DB::table('trainer_types')->insertGetId([
                    'account_id' => $account->id,
                    'name' => 'Trainer',
                    'icon' => 'user-round',
                    'color' => '#3B223F',
                    'is_default' => true,
                    'sort_order' => 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('trainers')
                    ->where('account_id', $account->id)
                    ->whereNull('trainer_type_id')
                    ->update(['trainer_type_id' => $trainerTypeId]);

                DB::table('class_pass_plans')
                    ->where('account_id', $account->id)
                    ->orderBy('id')
                    ->get()
                    ->each(function (object $classPassPlan) use ($trainerTypeId, $now): void {
                        DB::table('class_pass_plan_trainer_type')->insertOrIgnore([
                            'class_pass_plan_id' => $classPassPlan->id,
                            'trainer_type_id' => $trainerTypeId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    });
            });

        $charmpole = DB::table('accounts')->where('slug', 'charmpole')->first();

        if (! $charmpole) {
            return;
        }

        $topTrainerTypeId = DB::table('trainer_types')->insertGetId([
            'account_id' => $charmpole->id,
            'name' => 'TOP-trainer',
            'icon' => 'crown',
            'color' => '#D80A7D',
            'is_default' => false,
            'sort_order' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('trainers')
            ->where('account_id', $charmpole->id)
            ->where('name', 'Настя')
            ->update(['trainer_type_id' => $topTrainerTypeId]);

        DB::table('class_pass_plans')
            ->where('account_id', $charmpole->id)
            ->orderBy('id')
            ->get()
            ->each(function (object $classPassPlan) use ($topTrainerTypeId, $now): void {
                DB::table('class_pass_plan_trainer_type')->insertOrIgnore([
                    'class_pass_plan_id' => $classPassPlan->id,
                    'trainer_type_id' => $topTrainerTypeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('class_pass_plan_trainer_type')->delete();
        DB::table('trainers')->update(['trainer_type_id' => null]);
        DB::table('trainer_types')->delete();
    }
};
