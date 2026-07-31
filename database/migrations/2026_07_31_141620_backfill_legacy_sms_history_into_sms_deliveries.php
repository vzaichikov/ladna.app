<?php

use App\Support\Sms\LegacySmsDeliveryBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(LegacySmsDeliveryBackfill::class)->run();
    }

    /**
     * Historical audit rows are intentionally retained during rollback.
     */
    public function down(): void {}
};
