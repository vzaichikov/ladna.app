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
        Schema::create('finance_epochs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->boolean('is_legacy')->default(false);
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'starts_at', 'id']);
            $table->index(['account_id', 'is_legacy']);
        });

        DB::table('accounts')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->each(function (object $account): void {
                DB::table('finance_epochs')->insert([
                    'account_id' => $account->id,
                    'starts_at' => $account->created_at ?? now(),
                    'is_legacy' => true,
                    'reason' => 'Legacy history imported before cashbox reconciliation.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_epochs');
    }
};
