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
        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->dropForeign(['instructor_id']);
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->dropForeign(['instructor_id']);
        });

        Schema::table('instructors', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });

        Schema::rename('instructors', 'trainers');

        Schema::table('trainers', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->string('photo_path')->nullable()->after('bio');
            $table->index(['account_id', 'user_id']);
        });

        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->renameColumn('instructor_id', 'trainer_id');
        });

        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->foreign('trainer_id')->references('id')->on('trainers')->nullOnDelete();
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->renameColumn('instructor_id', 'trainer_id');
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->foreign('trainer_id')->references('id')->on('trainers')->nullOnDelete();
        });

        Schema::table('account_memberships', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        DB::table('account_memberships')
            ->where('role', 'instructor')
            ->update(['role' => 'trainer']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('account_memberships')
            ->where('role', 'trainer')
            ->update(['role' => 'instructor']);

        Schema::table('account_memberships', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });

        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->dropForeign(['trainer_id']);
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->dropForeign(['trainer_id']);
        });

        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->renameColumn('trainer_id', 'instructor_id');
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->renameColumn('trainer_id', 'instructor_id');
        });

        Schema::table('trainers', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['user_id']);
            $table->dropIndex(['account_id', 'user_id']);
            $table->dropColumn(['user_id', 'photo_path']);
        });

        Schema::rename('trainers', 'instructors');

        Schema::table('instructors', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });

        Schema::table('scheduled_classes', function (Blueprint $table) {
            $table->foreign('instructor_id')->references('id')->on('instructors')->nullOnDelete();
        });

        Schema::table('schedule_series', function (Blueprint $table) {
            $table->foreign('instructor_id')->references('id')->on('instructors')->nullOnDelete();
        });
    }
};
