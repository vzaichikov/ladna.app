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
        if (! Schema::hasTable('customer_purchase_refund_items')) {
            Schema::create('customer_purchase_refund_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id');
                $table->foreignId('customer_purchase_refund_id');
                $table->foreignId('customer_purchase_item_id');
                $table->unsignedInteger('amount_cents');
                $table->timestamps();
            });
        }

        $foreignKeys = collect(Schema::getForeignKeys('customer_purchase_refund_items'));
        Schema::table('customer_purchase_refund_items', function (Blueprint $table) use ($foreignKeys): void {
            if (! $foreignKeys->contains(fn (array $key): bool => $key['columns'] === ['account_id'])) {
                $table->foreign('account_id', 'purchase_refund_item_account_fk')->references('id')->on('accounts')->cascadeOnDelete();
            }
            if (! $foreignKeys->contains(fn (array $key): bool => $key['columns'] === ['customer_purchase_refund_id'])) {
                $table->foreign('customer_purchase_refund_id', 'purchase_refund_item_refund_fk')->references('id')->on('customer_purchase_refunds')->cascadeOnDelete();
            }
            if (! $foreignKeys->contains(fn (array $key): bool => $key['columns'] === ['customer_purchase_item_id'])) {
                $table->foreign('customer_purchase_item_id', 'purchase_refund_item_purchase_item_fk')->references('id')->on('customer_purchase_items')->restrictOnDelete();
            }
            if (! Schema::hasIndex('customer_purchase_refund_items', 'purchase_refund_items_refund_item_unique')) {
                $table->unique(['customer_purchase_refund_id', 'customer_purchase_item_id'], 'purchase_refund_items_refund_item_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_purchase_refund_items');
    }
};
