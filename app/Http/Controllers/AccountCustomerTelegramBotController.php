<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountCustomerTelegramBotRequest;
use App\Models\Account;
use App\Support\Telegram\CustomerTelegramBotConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountCustomerTelegramBotController extends Controller
{
    public function update(UpdateAccountCustomerTelegramBotRequest $request, Account $account, CustomerTelegramBotConnector $connector): RedirectResponse
    {
        $result = $connector->connect(
            $account,
            $request->validated('token'),
            $request->validated('welcome_message'),
        );

        return $this->redirect($account, $result);
    }

    public function reconnect(Request $request, Account $account, CustomerTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorize('manageStudioSettings', $account);
        $welcomeMessage = $account->telegramBotProfiles()
            ->where('profile', 'customer')
            ->value('welcome_message');

        return $this->redirect($account, $connector->connect($account, null, $welcomeMessage));
    }

    public function check(Request $request, Account $account, CustomerTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorize('manageStudioSettings', $account);

        return $this->redirect($account, $connector->check($account));
    }

    public function disable(Request $request, Account $account, CustomerTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorize('manageStudioSettings', $account);

        return $this->redirect($account, $connector->disable($account));
    }

    public function destroy(Request $request, Account $account, CustomerTelegramBotConnector $connector): RedirectResponse
    {
        $this->authorize('manageStudioSettings', $account);

        return $this->redirect($account, $connector->disconnect($account));
    }

    /**
     * @param  array{ok: bool, message: string}  $result
     */
    private function redirect(Account $account, array $result): RedirectResponse
    {
        $redirect = redirect()->route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'telegram']);

        return $result['ok']
            ? $redirect->with('status', $result['message'])
            : $redirect->withErrors(['telegram_bot' => $result['message']]);
    }
}
