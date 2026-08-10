<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $workflowNames = [
                'Standard registration' => 'Стандартна реєстрація',
                'Direct registration' => 'Пряма реєстрація',
                'Organizer review' => 'Перевірка організатором',
            ];

            $workflows = DB::table('festival_workflows')
                ->whereIn('account_id', DB::table('accounts')->select('id')->where('default_language', 'uk'))
                ->whereIn('name', array_keys($workflowNames))
                ->orderBy('id')
                ->get(['id', 'festival_edition_id', 'name']);

            foreach ($workflows as $workflow) {
                $localizedName = $workflowNames[$workflow->name];
                $duplicateExists = DB::table('festival_workflows')
                    ->where('festival_edition_id', $workflow->festival_edition_id)
                    ->where('name', $localizedName)
                    ->where('id', '!=', $workflow->id)
                    ->exists();

                if (! $duplicateExists) {
                    DB::table('festival_workflows')
                        ->where('id', $workflow->id)
                        ->where('name', $workflow->name)
                        ->update(['name' => $localizedName, 'updated_at' => now()]);
                }
            }

            $workflowIds = DB::table('festival_workflows')
                ->select('id')
                ->whereIn('account_id', DB::table('accounts')->select('id')->where('default_language', 'uk'));

            foreach ($this->stepTitles() as $title) {
                DB::table('festival_workflow_steps')
                    ->whereIn('festival_workflow_id', $workflowIds)
                    ->where('code', $title['code'])
                    ->where('title', $title['legacy'])
                    ->update(['title' => $title['localized'], 'updated_at' => now()]);
            }
        }, 3);
    }

    public function down(): void
    {
        // Forward-only localization backfill. Existing entry snapshots remain immutable.
    }

    /** @return array<int, array{code: string, legacy: string, localized: string}> */
    private function stepTitles(): array
    {
        return [
            [
                'code' => 'application',
                'legacy' => 'Application and qualification',
                'localized' => 'Заявка та кваліфікація',
            ],
            [
                'code' => 'application',
                'legacy' => 'Application',
                'localized' => 'Заявка',
            ],
            [
                'code' => 'participation_payment',
                'legacy' => 'Participation payment',
                'localized' => 'Оплата участі',
            ],
            [
                'code' => 'technical_form',
                'legacy' => 'Technical form',
                'localized' => 'Технічна анкета',
            ],
            [
                'code' => 'summary',
                'legacy' => 'Summary',
                'localized' => 'Підсумок',
            ],
        ];
    }
};
