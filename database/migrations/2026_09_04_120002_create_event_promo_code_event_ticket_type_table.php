<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_promo_code_event_ticket_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_ticket_type_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['event_promo_code_id', 'event_ticket_type_id'], 'event_promo_ticket_type_unique');
            $table->index(['event_id', 'event_ticket_type_id'], 'event_promo_ticket_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_promo_code_event_ticket_type');
    }
};
