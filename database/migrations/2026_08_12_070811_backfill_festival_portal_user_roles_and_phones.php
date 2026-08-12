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
        $conflictingIds = DB::table('festival_portal_users as portal_users')
            ->whereExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('festival_judge_assignments as assignments')
                ->whereColumn('assignments.festival_portal_user_id', 'portal_users.id'))
            ->where(fn ($query) => $query
                ->whereExists(fn ($participants) => $participants
                    ->selectRaw('1')
                    ->from('festival_participants as participants')
                    ->whereColumn('participants.festival_portal_user_id', 'portal_users.id'))
                ->orWhereExists(fn ($entries) => $entries
                    ->selectRaw('1')
                    ->from('festival_entries as entries')
                    ->whereColumn('entries.festival_portal_user_id', 'portal_users.id')))
            ->pluck('portal_users.id');

        if ($conflictingIds->isNotEmpty()) {
            throw new RuntimeException('Festival portal role backfill aborted: dual-use portal users ['.$conflictingIds->join(', ').'].');
        }

        $countryCodes = DB::table('accounts')->pluck('country_code', 'id');
        $normalizedById = [];
        $identityOwners = [];

        DB::table('festival_portal_users')
            ->select(['id', 'account_id', 'phone'])
            ->whereNotNull('phone')
            ->orderBy('id')
            ->chunkById(200, function ($portalUsers) use ($countryCodes, &$normalizedById, &$identityOwners): void {
                foreach ($portalUsers as $portalUser) {
                    $normalized = $this->normalizePhone($portalUser->phone, $countryCodes[$portalUser->account_id] ?? 'UA');

                    if ($normalized === null) {
                        continue;
                    }

                    $identityKey = $portalUser->account_id.'|'.$normalized;

                    if (isset($identityOwners[$identityKey]) && $identityOwners[$identityKey] !== $portalUser->id) {
                        throw new RuntimeException('Festival portal phone backfill aborted: normalized phone collision for portal users ['.$identityOwners[$identityKey].', '.$portalUser->id.'].');
                    }

                    $identityOwners[$identityKey] = $portalUser->id;
                    $normalizedById[$portalUser->id] = $normalized;
                }
            });

        DB::transaction(function () use ($normalizedById): void {
            DB::table('festival_portal_users')
                ->whereExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('festival_judge_assignments')
                    ->whereColumn('festival_judge_assignments.festival_portal_user_id', 'festival_portal_users.id'))
                ->update(['role' => 'judge']);

            DB::table('festival_portal_users')->whereNull('role')->update(['role' => 'registrant']);

            foreach ($normalizedById as $portalUserId => $normalizedPhone) {
                DB::table('festival_portal_users')->where('id', $portalUserId)->update([
                    'phone_normalized' => $normalizedPhone,
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('festival_portal_users')->update([
            'role' => null,
            'phone_normalized' => null,
        ]);
    }

    private function normalizePhone(?string $phone, ?string $countryCode): ?string
    {
        $value = trim((string) $phone);

        if ($value === '') {
            return null;
        }

        $hasInternationalPrefix = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?: '';

        if ($digits === '') {
            return null;
        }

        if ($hasInternationalPrefix) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (strtoupper((string) $countryCode) === 'UA') {
            if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
                return '+38'.$digits;
            }

            if (strlen($digits) === 12 && str_starts_with($digits, '380')) {
                return '+'.$digits;
            }
        }

        return '+'.$digits;
    }
};
