<?php

namespace App\Actions\Festivals;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalSponsor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SaveFestivalSponsor
{
    /** @param array{name: string, url: ?string, short_description: string, giveaway_description: ?string, show_on_all_pages: bool} $input */
    public function execute(Account $account, FestivalEdition $edition, FestivalSponsor $sponsor, array $input, ?UploadedFile $logo): FestivalSponsor
    {
        abort_unless($edition->account_id === $account->id, 404);
        $newPath = null;
        $oldPath = null;

        try {
            if ($logo !== null) {
                $newPath = $logo->store("festivals/{$account->id}/{$edition->id}/sponsors", 'public');

                if (! is_string($newPath)) {
                    throw new RuntimeException('Unable to store the Festival sponsor logo.');
                }
            }

            $sponsor = DB::transaction(function () use ($account, $edition, $sponsor, $input, $newPath, &$oldPath): FestivalSponsor {
                $savedSponsor = $sponsor->exists
                    ? FestivalSponsor::query()
                        ->whereBelongsTo($account)
                        ->where('festival_edition_id', $edition->id)
                        ->whereKey($sponsor->id)
                        ->lockForUpdate()
                        ->firstOrFail()
                    : new FestivalSponsor(['account_id' => $account->id, 'festival_edition_id' => $edition->id]);

                $savedSponsor->fill($input);

                if (is_string($newPath)) {
                    $oldPath = $savedSponsor->logo_path;
                    $savedSponsor->logo_path = $newPath;
                }

                $savedSponsor->save();

                return $savedSponsor;
            }, 3);
        } catch (Throwable $exception) {
            if (is_string($newPath)) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        if (is_string($oldPath) && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $sponsor;
    }
}
