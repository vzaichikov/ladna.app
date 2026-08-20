<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Support\Mcp\McpConnectionGuide;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

class McpConnectionGuideController extends Controller
{
    public function show(Account $account, McpConnectionGuide $connectionGuide): Response
    {
        $this->prepareAccount($account);

        return response()->view('mcp.connection-guide', [
            'account' => $account,
            'guide' => $connectionGuide->forAccount($account),
            'disablePublicPwa' => true,
        ])->withHeaders($this->guideHeaders());
    }

    public function markdown(Account $account, McpConnectionGuide $connectionGuide): Response
    {
        $this->prepareAccount($account);

        return response($connectionGuide->markdown($account))
            ->withHeaders([
                'Content-Type' => 'text/markdown; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                ...$this->guideHeaders(),
            ]);
    }

    private function prepareAccount(Account $account): void
    {
        abort_unless($account->status === AccountStatus::Active, 404);

        if (array_key_exists($account->default_language, config('ladna.locales'))) {
            App::setLocale($account->default_language);
            Carbon::setLocale($account->default_language);
        }
    }

    /**
     * @return array<string, string>
     */
    private function guideHeaders(): array
    {
        return [
            'Content-Language' => App::currentLocale(),
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
