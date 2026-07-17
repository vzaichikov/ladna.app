<?php

namespace App\Support\PeopleCounter;

use App\Models\Account;
use App\Support\DemoStudioFixture;
use Illuminate\Support\Facades\Storage;

class PeopleCounterImageLocator
{
    public function exists(Account $account, ?string $path): bool
    {
        return $this->absolutePath($account, $path) !== null;
    }

    public function absolutePath(Account $account, ?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        if ($account->isReadOnlyDemo()) {
            $assetPath = DemoStudioFixture::cameraAssetPath($path);

            if ($assetPath === null) {
                return null;
            }

            $absolutePath = public_path($assetPath);

            return is_file($absolutePath) ? $absolutePath : null;
        }

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->path($path);
    }
}
