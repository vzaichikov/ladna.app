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
        Schema::create('account_subscription_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_subscription_id');
            $table->string('notification_type');
            $table->timestamp('scheduled_for');
            $table->timestamp('sent_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->foreign('account_subscription_id', 'saas_notifications_subscription_fk')
                ->references('id')->on('account_subscriptions')->cascadeOnDelete();
            $table->unique(
                ['account_subscription_id', 'notification_type', 'scheduled_for'],
                'saas_notifications_delivery_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_subscription_notifications');
    }
};
