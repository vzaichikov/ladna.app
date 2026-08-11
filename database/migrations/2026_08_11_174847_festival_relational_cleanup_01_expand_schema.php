<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('festival_entry_steps', function (Blueprint $table): void {
            $table->timestamp('correction_due_at')->nullable()->after('revision_due_at');
        });
    }

    /**
     * This expand migration is part of an intentionally forward-only cleanup.
     */
    public function down(): void {}
};
