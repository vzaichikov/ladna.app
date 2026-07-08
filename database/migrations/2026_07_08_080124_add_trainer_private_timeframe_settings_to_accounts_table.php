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
            $table->boolean('trainer_private_timeframes_enabled')
                ->default(false)
                ->after('schedule_generation_weeks');
            $table->unsignedSmallInteger('trainer_private_timeframe_weeks')
                ->nullable()
                ->after('trainer_private_timeframes_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'trainer_private_timeframes_enabled',
                'trainer_private_timeframe_weeks',
            ]);
        });
    }
};
