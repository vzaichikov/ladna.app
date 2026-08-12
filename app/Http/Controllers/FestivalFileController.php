<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FestivalDocument;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FestivalFileController extends Controller
{
    /** @var list<string> */
    private const INLINE_PREVIEW_MIME_TYPES = [
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'audio/wav',
        'audio/x-wav',
        'image/avif',
        'image/gif',
        'image/jpeg',
        'image/png',
        'image/webp',
        'video/mp4',
        'video/ogg',
        'video/webm',
    ];

    public function submission(Request $request, Account $account, FestivalSubmission $festivalSubmission): StreamedResponse
    {
        $this->authorizeSubmission($request, $account, $festivalSubmission);

        return Storage::disk($festivalSubmission->disk)->download($festivalSubmission->path, $festivalSubmission->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function viewSubmission(Request $request, Account $account, FestivalSubmission $festivalSubmission): StreamedResponse
    {
        $this->authorizeSubmission($request, $account, $festivalSubmission);

        if (! in_array(strtolower((string) $festivalSubmission->mime_type), self::INLINE_PREVIEW_MIME_TYPES, true)) {
            return Storage::disk($festivalSubmission->disk)->download($festivalSubmission->path, $festivalSubmission->original_name, [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Storage::disk($festivalSubmission->disk)->response($festivalSubmission->path, $festivalSubmission->original_name, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => strtolower((string) $festivalSubmission->mime_type),
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

    private function authorizeSubmission(Request $request, Account $account, FestivalSubmission $submission): void
    {
        abort_unless($submission->account_id === $account->id, 404);
        $submission->loadMissing('entry');
        $staffUser = $request->user('web');
        $staffAllowed = $staffUser && collect(['manageFestivals', 'manageFestivalRegistrations'])->contains(fn (string $ability): bool => $staffUser->can($ability, $account));
        $judgeAllowed = $staffUser
            && $staffUser->can('judgeFestivals', $account)
            && $submission->entry
            && FestivalJudgeAssignment::query()
                ->where('account_id', $account->id)
                ->where('festival_edition_id', $submission->entry->festival_edition_id)
                ->where('user_id', $staffUser->id)
                ->where('is_active', true)
                ->whereHas('categories', fn ($categories) => $categories->whereKey($submission->entry->festival_category_id))
                ->exists();
        $portalAllowed = $request->user('festival')?->id === $submission->festival_portal_user_id;
        abort_unless($staffAllowed || $judgeAllowed || $portalAllowed, 403);
    }
}
