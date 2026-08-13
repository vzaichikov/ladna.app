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
        $hasUniqueTicket = collect(Schema::getIndexes('festival_stream_entitlements'))
            ->contains(fn (array $index): bool => $index['columns'] === ['festival_ticket_id'] && $index['unique']);

        if (! $hasUniqueTicket) {
            Schema::table('festival_stream_entitlements', function (Blueprint $table) {
                $table->unique('festival_ticket_id', 'festival_stream_entitlement_ticket_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $indexNames = collect(Schema::getIndexes('festival_stream_entitlements'))->pluck('name');

        if ($indexNames->contains('festival_stream_entitlement_ticket_unique')) {
            Schema::table('festival_stream_entitlements', function (Blueprint $table) {
                $table->dropUnique('festival_stream_entitlement_ticket_unique');
            });
        }
    }
};
