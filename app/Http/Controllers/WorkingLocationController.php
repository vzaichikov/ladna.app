<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateWorkingLocationRequest;
use App\Models\Account;
use App\Support\WorkingLocationContext;
use Illuminate\Http\RedirectResponse;

class WorkingLocationController extends Controller
{
    public function __invoke(
        UpdateWorkingLocationRequest $request,
        Account $account,
        WorkingLocationContext $workingLocationContext,
    ): RedirectResponse {
        $value = (string) $request->validated('location_context');

        return redirect($this->redirectUrl(
            (string) $request->validated('redirect_to', ''),
            $account,
            $value,
        ))->cookie($workingLocationContext->cookie($account, $value));
    }

    private function redirectUrl(string $requestedUrl, Account $account, string $value): string
    {
        $parts = parse_url($requestedUrl);

        if (
            ! is_array($parts)
            || isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])
            || ! str_starts_with((string) ($parts['path'] ?? ''), '/')
        ) {
            return route('dashboard.accounts.show', [
                'account' => $account,
                WorkingLocationContext::QueryKey => $value,
            ], absolute: false);
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['location_id'], $query['locations'], $query['filters_submitted'], $query['page']);
        $query[WorkingLocationContext::QueryKey] = $value;
        $queryString = http_build_query($query);

        return (string) $parts['path'].($queryString !== '' ? '?'.$queryString : '');
    }
}
