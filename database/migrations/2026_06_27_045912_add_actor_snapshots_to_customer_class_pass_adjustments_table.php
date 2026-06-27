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
        Schema::table('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_user_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('actor_trainer_id')->nullable()->after('actor_user_id');
            $table->string('actor_name')->nullable()->after('actor_trainer_id');
            $table->string('actor_email')->nullable()->after('actor_name');
            $table->string('actor_role')->nullable()->after('actor_email');

            $table->index(['account_id', 'actor_user_id'], 'pass_adjustments_actor_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_pass_adjustments', function (Blueprint $table) {
            $table->dropIndex('pass_adjustments_actor_index');
            $table->dropColumn([
                'actor_user_id',
                'actor_trainer_id',
                'actor_name',
                'actor_email',
                'actor_role',
            ]);
        });
    }
};
