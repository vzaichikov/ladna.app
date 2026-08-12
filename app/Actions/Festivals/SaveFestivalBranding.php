<?php

namespace App\Actions\Festivals;

use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SaveFestivalBranding
{
    public function __construct(private readonly FestivalActivityRecorder $activity) {}

    /** @param array{landing_palette: string, landing_template?: string} $input */
    public function execute(
        Account $account,
        FestivalEdition $edition,
        array $input,
        User $actor,
        ?UploadedFile $heroImage = null,
        ?UploadedFile $mobileHeroImage = null,
    ): FestivalEdition {
        $newPath = null;
        $newMobilePath = null;
        /** @var Collection<int, string> $oldPaths */
        $oldPaths = collect();

        try {
            $newPath = $heroImage?->store("festival-media/{$account->id}/{$edition->id}", 'public');
            $newMobilePath = $mobileHeroImage?->store("festival-media/{$account->id}/{$edition->id}", 'public');

            DB::transaction(function () use ($account, $edition, $input, $actor, $newPath, $newMobilePath, &$oldPaths): void {
                $lockedEdition = FestivalEdition::query()
                    ->whereBelongsTo($account)
                    ->whereKey($edition->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (array_key_exists('landing_template', $input)) {
                    $lockedEdition->landing_template = $input['landing_template'];
                }

                $lockedEdition->landing_palette = $input['landing_palette'];
                $lockedEdition->save();

                if (is_string($newPath)) {
                    $previousCovers = FestivalMedia::query()
                        ->where('festival_edition_id', $lockedEdition->id)
                        ->where('is_cover', true)
                        ->lockForUpdate()
                        ->get();

                    $oldPaths = $previousCovers
                        ->filter(fn (FestivalMedia $media): bool => $media->disk === 'public' && filled($media->path))
                        ->pluck('path');

                    FestivalMedia::query()
                        ->where('festival_edition_id', $lockedEdition->id)
                        ->where('is_cover', true)
                        ->update(['is_cover' => false]);

                    FestivalMedia::query()->create([
                        'account_id' => $account->id,
                        'festival_edition_id' => $lockedEdition->id,
                        'kind' => 'image',
                        'disk' => 'public',
                        'path' => $newPath,
                        'alt_text' => $lockedEdition->title,
                        'is_cover' => true,
                        'is_mobile_cover' => false,
                    ]);

                    $previousCovers
                        ->filter(fn (FestivalMedia $media): bool => filled($media->path))
                        ->each->delete();
                }

                if (is_string($newMobilePath)) {
                    $previousMobileCovers = FestivalMedia::query()
                        ->where('festival_edition_id', $lockedEdition->id)
                        ->where('is_mobile_cover', true)
                        ->lockForUpdate()
                        ->get();

                    $oldPaths = $oldPaths->merge(
                        $previousMobileCovers
                            ->filter(fn (FestivalMedia $media): bool => $media->disk === 'public' && filled($media->path))
                            ->pluck('path'),
                    );

                    FestivalMedia::query()
                        ->where('festival_edition_id', $lockedEdition->id)
                        ->where('is_mobile_cover', true)
                        ->update(['is_mobile_cover' => false]);

                    FestivalMedia::query()->create([
                        'account_id' => $account->id,
                        'festival_edition_id' => $lockedEdition->id,
                        'kind' => 'image',
                        'disk' => 'public',
                        'path' => $newMobilePath,
                        'alt_text' => $lockedEdition->title,
                        'is_cover' => false,
                        'is_mobile_cover' => true,
                    ]);

                    $previousMobileCovers
                        ->filter(fn (FestivalMedia $media): bool => filled($media->path))
                        ->each->delete();
                }

                $this->activity->record($lockedEdition, 'edition.branding_updated', $lockedEdition, $actor, [
                    'landing_template' => $lockedEdition->landing_template,
                    'landing_palette' => $lockedEdition->landing_palette,
                    'hero_replaced' => is_string($newPath),
                    'mobile_hero_replaced' => is_string($newMobilePath),
                ]);
            }, 3);
        } catch (Throwable $throwable) {
            collect([$newPath, $newMobilePath])
                ->filter(fn (mixed $path): bool => is_string($path))
                ->each(fn (string $path): bool => Storage::disk('public')->delete($path));

            throw $throwable;
        }

        $oldPaths->each(fn (string $oldPath): bool => Storage::disk('public')->delete($oldPath));

        return $edition->refresh();
    }
}
