<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FestivalDocument;
use App\Models\FestivalJudgeAssignment;
use App\Models\FestivalSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FestivalFileController extends Controller
{
    public function submission(Request $request, Account $account, FestivalSubmission $festivalSubmission): StreamedResponse
    {
        $this->authorizeSubmission($request, $account, $festivalSubmission);

        return Storage::disk($festivalSubmission->disk)->download($festivalSubmission->path, $festivalSubmission->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function viewSubmission(Request $request, Account $account, FestivalSubmission $festivalSubmission): BinaryFileResponse|StreamedResponse
    {
        $this->authorizeSubmission($request, $account, $festivalSubmission);

        if (! $festivalSubmission->isInlinePreviewable()) {
            return Storage::disk($festivalSubmission->disk)->download($festivalSubmission->path, $festivalSubmission->original_name, [
                'Cache-Control' => 'private, no-store',
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if ($festivalSubmission->playbackKind() !== null && config("filesystems.disks.{$festivalSubmission->disk}.driver") === 'local') {
            $disk = Storage::disk($festivalSubmission->disk);
            abort_unless($disk->exists($festivalSubmission->path), 404);
            $response = new BinaryFileResponse(
                $disk->path($festivalSubmission->path),
                headers: [
                    'Cache-Control' => 'private, no-store',
                    'Content-Type' => strtolower((string) $festivalSubmission->mime_type),
                    'X-Content-Type-Options' => 'nosniff',
                ],
                public: false,
            );
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, (string) $festivalSubmission->original_name);

            return $response;
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
