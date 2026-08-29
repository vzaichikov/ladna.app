<?php

namespace App\Http\Controllers;

use App\Actions\Festivals\DetectFestivalApplicationMediaDuplicates;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Support\Festivals\FestivalMediaDuplicateAnalysisException;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FestivalApplicationMediaDuplicateController extends Controller
{
    public function __construct(
        private readonly FestivalWorkspaceAccess $workspaceAccess,
        private readonly DetectFestivalApplicationMediaDuplicates $duplicateDetector,
    ) {}

    public function __invoke(Request $request, Account $account, FestivalEdition $festivalEdition): JsonResponse
    {
        abort_unless($festivalEdition->account_id === $account->id, 404);
        $permissions = $this->workspaceAccess->permissions($request->user(), $account, $festivalEdition);
        abort_unless($permissions['registrations'], 403);

        try {
            $response = response()->json(
                $this->duplicateDetector->execute($account, $festivalEdition, $request->user()),
            );
        } catch (FestivalMediaDuplicateAnalysisException $exception) {
            $response = response()->json([
                'message' => $exception->getMessage(),
                'code' => $exception->reason,
                'retry_after' => $exception->retryAfterSeconds,
            ], $exception->httpStatus);

            if ($exception->retryAfterSeconds !== null) {
                $response->headers->set('Retry-After', (string) $exception->retryAfterSeconds);
            }
        }

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
