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
        if (! Schema::hasColumn('telegram_bot_installations', 'scope_type')) {
            Schema::table('telegram_bot_installations', function (Blueprint $table): void {
                $table->dropForeign(['account_id']);
            });

            Schema::table('telegram_bot_installations', function (Blueprint $table): void {
                $table->dropUnique('telegram_bot_installations_account_id_profile_unique');
            });

            Schema::table('telegram_bot_installations', function (Blueprint $table): void {
                $table->foreignId('account_id')->nullable()->change();
                $table->string('scope_type')->default('account')->after('account_id');
                $table->unsignedBigInteger('scope_id')->default(0)->after('scope_type');
                $table->unique(['scope_type', 'scope_id', 'profile'], 'telegram_bot_installations_scope_profile_unique');
                $table->index(['scope_type', 'profile', 'is_enabled'], 'telegram_bot_installations_scope_profile_enabled_index');
                $table->foreign('account_id')
                    ->references('id')
                    ->on('accounts')
                    ->cascadeOnDelete();
            });

            DB::table('telegram_bot_installations')
                ->where('scope_type', 'account')
                ->where('scope_id', 0)
                ->update(['scope_id' => DB::raw('account_id')]);
        }

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->change();
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });

        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });

        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->change();
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });

        if (! Schema::hasColumn('ai_conversations', 'user_id')) {
            Schema::table('ai_conversations', function (Blueprint $table): void {
                $table->foreignId('user_id')->nullable()->after('telegram_chat_authorization_id')->constrained()->nullOnDelete();
                $table->foreignId('trainer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
                $table->index(['account_id', 'channel', 'user_id', 'status'], 'ai_conversations_user_channel_index');
                $table->index(['account_id', 'channel', 'trainer_id', 'status'], 'ai_conversations_trainer_channel_index');
            });
        }

        if (! Schema::hasTable('ai_pending_actions')) {
            Schema::create('ai_pending_actions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ai_conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('trainer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action_name');
                $table->json('arguments');
                $table->json('preview')->nullable();
                $table->string('status')->default('pending');
                $table->json('result')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();

                $table->index(['account_id', 'status', 'expires_at'], 'ai_pending_actions_account_status_index');
                $table->index(['ai_conversation_id', 'status'], 'ai_pending_actions_conversation_status_index');
            });
        }

        if (! Schema::hasTable('telegram_authorization_selections')) {
            Schema::create('telegram_authorization_selections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('telegram_bot_installation_id');
                $table->string('profile');
                $table->string('telegram_chat_id');
                $table->string('telegram_user_id');
                $table->string('telegram_username')->nullable();
                $table->string('phone');
                $table->string('status')->default('pending');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['telegram_bot_installation_id', 'telegram_chat_id', 'status'], 'tg_auth_selections_installation_chat_index');
                $table->foreign('telegram_bot_installation_id', 'tg_auth_selections_installation_fk')
                    ->references('id')
                    ->on('telegram_bot_installations')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('telegram_authorization_selection_candidates')) {
            Schema::create('telegram_authorization_selection_candidates', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('telegram_authorization_selection_id');
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('trainer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('label');
                $table->timestamps();

                $table->index(['telegram_authorization_selection_id', 'account_id'], 'tg_auth_selection_candidates_lookup_index');
                $table->foreign('telegram_authorization_selection_id', 'tg_auth_selection_candidates_selection_fk')
                    ->references('id')
                    ->on('telegram_authorization_selections')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_authorization_selection_candidates');
        Schema::dropIfExists('telegram_authorization_selections');
        Schema::dropIfExists('ai_pending_actions');

        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropIndex('ai_conversations_user_channel_index');
            $table->dropIndex('ai_conversations_trainer_channel_index');
            $table->dropConstrainedForeignId('trainer_id');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });

        Schema::table('telegram_messages', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable(false)->change();
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable(false)->change();
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });

        Schema::table('telegram_bot_installations', function (Blueprint $table): void {
            $table->dropUnique('telegram_bot_installations_scope_profile_unique');
            $table->dropIndex('telegram_bot_installations_scope_profile_enabled_index');
            $table->dropForeign(['account_id']);
            $table->dropColumn(['scope_type', 'scope_id']);
        });

        Schema::table('telegram_bot_installations', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable(false)->change();
            $table->unique(['account_id', 'profile']);
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();
        });
    }
};
