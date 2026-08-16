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
        Schema::table('event_ticket_check_ins', function (Blueprint $table) {
            $table->string('request_ip', 45)->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('event_ticket_check_ins', function (Blueprint $table) {
            $table->dropColumn('request_ip');
        });
    }
};
