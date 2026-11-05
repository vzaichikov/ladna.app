<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('festival_editions', function (Blueprint $table): void {
            $table->boolean('assigned_seating_enabled')->default(false);
            $table->json('seating_floors')->nullable();
        });

        Schema::table('festival_admission_types', function (Blueprint $table): void {
            $table->boolean('is_assigned_seating')->default(false);
        });

        Schema::create('festival_seats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_admission_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('floor_key', 48);
            $table->string('section', 80);
            $table->string('group_kind', 12);
            $table->string('group_label', 40);
            $table->string('seat_number', 24);
            $table->unsignedSmallInteger('x');
            $table->unsignedSmallInteger('y');
            $table->string('accessibility', 255)->nullable();
            $table->string('sightline', 255)->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->char('hold_key', 64)->nullable();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamps();
            $table->index(['festival_edition_id', 'hold_key', 'hold_expires_at'], 'festival_seats_edition_hold_idx');
        });

        Schema::table('festival_ticket_orders', function (Blueprint $table): void {
            $table->char('seat_hold_key', 64)->nullable()->unique();
        });

        Schema::table('festival_tickets', function (Blueprint $table): void {
            $table->foreignId('festival_seat_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['festival_ticket_order_id', 'festival_seat_id'], 'festival_tickets_order_seat_unique');
        });

        Schema::create('festival_ticket_order_seats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('festival_ticket_order_item_id')->constrained(indexName: 'festival_order_seats_item_fk')->cascadeOnDelete();
            $table->foreignId('festival_seat_id')->constrained()->restrictOnDelete();
            $table->unique(['festival_ticket_order_item_id', 'festival_seat_id'], 'festival_order_seats_item_seat_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('festival_ticket_order_seats');

        Schema::table('festival_tickets', function (Blueprint $table): void {
            $table->dropUnique('festival_tickets_order_seat_unique');
            $table->dropConstrainedForeignId('festival_seat_id');
        });

        Schema::table('festival_ticket_orders', function (Blueprint $table): void {
            $table->dropUnique(['seat_hold_key']);
            $table->dropColumn('seat_hold_key');
        });

        Schema::dropIfExists('festival_seats');
        Schema::table('festival_admission_types', fn (Blueprint $table) => $table->dropColumn('is_assigned_seating'));
        Schema::table('festival_editions', fn (Blueprint $table) => $table->dropColumn(['assigned_seating_enabled', 'seating_floors']));
    }
};
