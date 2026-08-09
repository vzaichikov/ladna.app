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
        Schema::create('festival_portal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('registrant_type')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('patronymic')->nullable();
            $table->string('email');
            $table->string('email_normalized');
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('studio_name')->nullable();
            $table->string('instagram_url', 2048)->nullable();
            $table->string('telegram_user_id')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('locale', 5)->default('uk');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['account_id', 'email_normalized']);
            $table->unique(['account_id', 'telegram_user_id']);
            $table->index(['account_id', 'last_name', 'first_name']);
        });

        Schema::create('festival_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_normalized');
            $table->char('token_hash', 64)->unique();
            $table->char('request_ip_hash', 64)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'email_normalized', 'created_at'], 'festival_login_email_created_idx');
        });

        Schema::create('festival_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('festival_portal_user_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('patronymic')->nullable();
            $table->date('date_of_birth');
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
            $table->index(['festival_portal_user_id', 'archived_at', 'last_name'], 'festival_participants_roster_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('festival_participants');
        Schema::dropIfExists('festival_login_tokens');
        Schema::dropIfExists('festival_portal_users');
    }
};
