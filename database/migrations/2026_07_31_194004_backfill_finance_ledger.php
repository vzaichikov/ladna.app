<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exitCode = Artisan::call('finance:backfill-ledger');

        if ($exitCode !== 0) {
            throw new RuntimeException('Finance ledger backfill failed: '.Artisan::output());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
