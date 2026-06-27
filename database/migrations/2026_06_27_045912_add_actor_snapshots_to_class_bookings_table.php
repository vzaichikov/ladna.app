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
        Schema::table('class_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('booked_by_actor_user_id')->nullable()->after('booked_by_user_id');
            $table->unsignedBigInteger('booked_by_actor_trainer_id')->nullable()->after('booked_by_actor_user_id');
            $table->string('booked_by_actor_name')->nullable()->after('booked_by_actor_trainer_id');
            $table->string('booked_by_actor_email')->nullable()->after('booked_by_actor_name');
            $table->string('booked_by_actor_role')->nullable()->after('booked_by_actor_email');

            $table->index(['account_id', 'booked_by_actor_user_id'], 'class_bookings_actor_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_bookings', function (Blueprint $table) {
            $table->dropIndex('class_bookings_actor_index');
            $table->dropColumn([
                'booked_by_actor_user_id',
                'booked_by_actor_trainer_id',
                'booked_by_actor_name',
                'booked_by_actor_email',
                'booked_by_actor_role',
            ]);
        });
    }
};
