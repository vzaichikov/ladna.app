<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PeopleCounterSample;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PeopleCounterScreenshotController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Account $account, PeopleCounterSample $peopleCounterSample, string $variant): BinaryFileResponse
    {
        $this->authorize('viewReports', $account);
        abort_unless($peopleCounterSample->account_id === $account->id, 404);
        abort_unless(in_array($variant, ['original', 'masked'], true), 404);

        $path = $variant === 'original'
            ? $peopleCounterSample->original_image_path
            : $peopleCounterSample->masked_image_path;

        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/jpeg']);
    }
}
