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
        Schema::create('class_pass_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('schedule_kind')->default('group_class');
            $table->string('name');
            $table->string('slug');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'slug']);
            $table->index(['account_id', 'schedule_kind', 'is_active', 'sort_order'], 'class_pass_segments_account_kind_active_sort_index');
        });

        Schema::create('activity_direction_class_pass_segment', function (Blueprint $table) {
            $table->foreignId('activity_direction_id');
            $table->foreignId('class_pass_segment_id');
            $table->timestamps();

            $table->primary(['activity_direction_id', 'class_pass_segment_id'], 'direction_class_pass_segment_primary');
            $table->index('class_pass_segment_id', 'direction_class_pass_segment_segment_index');
            $table->foreign('activity_direction_id', 'direction_segment_direction_fk')
                ->references('id')
                ->on('activity_directions')
                ->cascadeOnDelete();
            $table->foreign('class_pass_segment_id', 'direction_segment_segment_fk')
                ->references('id')
                ->on('class_pass_segments')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_direction_class_pass_segment');
        Schema::dropIfExists('class_pass_segments');
    }
};
