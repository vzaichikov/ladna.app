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
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->unsignedBigInteger('issued_by_actor_user_id')->nullable()->after('source');
            $table->unsignedBigInteger('issued_by_actor_trainer_id')->nullable()->after('issued_by_actor_user_id');
            $table->string('issued_by_actor_name')->nullable()->after('issued_by_actor_trainer_id');
            $table->string('issued_by_actor_email')->nullable()->after('issued_by_actor_name');
            $table->string('issued_by_actor_role')->nullable()->after('issued_by_actor_email');

            $table->index(['account_id', 'issued_by_actor_user_id'], 'customer_passes_issuer_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_class_passes', function (Blueprint $table) {
            $table->dropIndex('customer_passes_issuer_index');
            $table->dropColumn([
                'issued_by_actor_user_id',
                'issued_by_actor_trainer_id',
                'issued_by_actor_name',
                'issued_by_actor_email',
                'issued_by_actor_role',
            ]);
        });
    }
};
