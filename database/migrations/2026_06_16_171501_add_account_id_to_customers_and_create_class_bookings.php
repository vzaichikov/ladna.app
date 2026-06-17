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
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('customers')
            ->leftJoin('customer_account', 'customers.id', '=', 'customer_account.customer_id')
            ->whereNull('customers.account_id')
            ->update(['customers.account_id' => DB::raw('customer_account.account_id')]);

        $fallbackAccountId = DB::table('accounts')->value('id');

        if ($fallbackAccountId !== null) {
            DB::table('customers')
                ->whereNull('account_id')
                ->update(['account_id' => $fallbackAccountId]);
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropUnique(['phone']);
            $table->dropUnique(['google_id']);
            $table->unique(['account_id', 'email']);
            $table->unique(['account_id', 'phone']);
            $table->unique(['account_id', 'google_id']);
            $table->index(['account_id', 'created_at']);
        });

        Schema::dropIfExists('customer_account');

        Schema::create('class_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheduled_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('booked');
            $table->timestamp('attended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['scheduled_class_id', 'customer_id']);
            $table->index(['account_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_bookings');

        Schema::create('customer_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'account_id']);
            $table->index('account_id');
        });

        DB::table('customers')
            ->whereNotNull('account_id')
            ->orderBy('id')
            ->select(['id', 'account_id', 'created_at', 'updated_at'])
            ->chunkById(100, function ($customers): void {
                foreach ($customers as $customer) {
                    DB::table('customer_account')->insert([
                        'customer_id' => $customer->id,
                        'account_id' => $customer->account_id,
                        'created_at' => $customer->created_at,
                        'updated_at' => $customer->updated_at,
                    ]);
                }
            });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'email']);
            $table->dropUnique(['account_id', 'phone']);
            $table->dropUnique(['account_id', 'google_id']);
            $table->dropIndex(['account_id', 'created_at']);
            $table->unique('email');
            $table->unique('phone');
            $table->unique('google_id');
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
