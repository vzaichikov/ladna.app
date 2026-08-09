<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FestivalDocument;
use App\Models\FestivalSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FestivalFileController extends Controller
{
    public function submission(Request $request, Account $account, FestivalSubmission $festivalSubmission): StreamedResponse
    {
        abort_unless($festivalSubmission->account_id === $account->id, 404);
        $staffAllowed = $request->user('web') && collect(['manageFestivals', 'manageFestivalRegistrations', 'judgeFestivals'])->contains(fn (string $ability): bool => $request->user('web')->can($ability, $account));
        $portalAllowed = $request->user('festival')?->id === $festivalSubmission->festival_portal_user_id;
        abort_unless($staffAllowed || $portalAllowed, 403);

        return Storage::disk($festivalSubmission->disk)->download($festivalSubmission->path, $festivalSubmission->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function portalSubmission(Request $request, string $accountSlug, FestivalSubmission $festivalSubmission): StreamedResponse
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $festivalSubmission->account_id === $account->id && $festivalSubmission->festival_portal_user_id === $request->user('festival')?->id, 404);

        return Storage::disk($festivalSubmission->disk)->download($festivalSubmission->path, $festivalSubmission->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function document(Request $request, string $accountSlug, FestivalDocument $festivalDocument): StreamedResponse
    {
        $account = $request->attributes->get('festivalAccount');
        abort_unless($account instanceof Account && $account->slug === $accountSlug && $festivalDocument->account_id === $account->id && $festivalDocument->visibility === 'public', 404);

        return Storage::disk($festivalDocument->disk)->download($festivalDocument->path, $festivalDocument->original_name, ['Cache-Control' => 'public, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
