<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PeopleCounterSample;
use App\Support\PeopleCounter\PeopleCounterImageLocator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PeopleCounterScreenshotController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Account $account, PeopleCounterSample $peopleCounterSample, string $variant, PeopleCounterImageLocator $imageLocator): BinaryFileResponse
    {
        $this->authorize('viewReports', $account);
        abort_unless($peopleCounterSample->account_id === $account->id, 404);
        abort_unless(in_array($variant, ['original', 'masked'], true), 404);

        $path = $variant === 'original'
            ? $peopleCounterSample->original_image_path
            : $peopleCounterSample->masked_image_path;

        $absolutePath = $imageLocator->absolutePath($account, $path);

        abort_unless($absolutePath !== null, 404);

        return response()->file($absolutePath, ['Content-Type' => 'image/jpeg']);
    }
}
