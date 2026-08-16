<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->updatePermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}

    private function updatePermissions(): void
    {
        DB::table('account_memberships')
            ->whereNotNull('permissions')
            ->orderBy('id')
            ->chunkById(100, function ($memberships): void {
                foreach ($memberships as $membership) {
                    $permissions = json_decode((string) $membership->permissions, true);

                    if (! is_array($permissions)) {
                        continue;
                    }

                    $hasBothLegacyPermissions = in_array('check_in_event_tickets', $permissions, true)
                        && in_array('check_in_festival_tickets', $permissions, true);

                    if ($hasBothLegacyPermissions && ! in_array('door_staff', $permissions, true)) {
                        $permissions[] = 'door_staff';
                    }

                    DB::table('account_memberships')
                        ->where('id', $membership->id)
                        ->update([
                            'permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR),
                        ]);
                }
            });
    }
};
