<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('festival_entrance_pass_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_edition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_entrance_pass_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('source')->default('qr');
            $table->string('request_ip')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->index(['festival_entrance_pass_id', 'occurred_at'], 'festival_entrance_pass_scans_pass_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('festival_entrance_pass_scans');
    }
};
