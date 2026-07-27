<?php

namespace App\Http\Controllers\Platform;

use App\Enums\EmailScenario;
use App\Enums\EmailScenarioGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateEmailScenarioSettingsRequest;
use App\Support\Mail\EmailScenarioPreviewFactory;
use App\Support\Mail\EmailScenarioSettings;
use App\Support\Mail\MailDeliverySettingsResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EmailScenarioController extends Controller
{
    public function index(
        EmailScenarioSettings $scenarioSettings,
        MailDeliverySettingsResolver $mailSettingsResolver,
    ): View {
        $scenarios = collect(EmailScenario::cases());

        return view('platform.email-scenarios.index', [
            'enabledMap' => $scenarioSettings->enabledMap(),
            'groups' => collect(EmailScenarioGroup::cases())->map(fn (EmailScenarioGroup $group): array => [
                'group' => $group,
                'scenarios' => $scenarios->filter(fn (EmailScenario $scenario): bool => $scenario->group() === $group)->values(),
            ]),
            'mailSettings' => $mailSettingsResolver->resolve(),
        ]);
    }

    public function update(
        UpdateEmailScenarioSettingsRequest $request,
        EmailScenarioSettings $scenarioSettings,
    ): RedirectResponse {
        $scenarioSettings->save($request->scenarioSettings());
        $activeTab = EmailScenarioGroup::tryFrom($request->string('scenario_tab')->value());

        return redirect()
            ->route('platform.email-scenarios.index', $activeTab ? ['tab' => $activeTab->value] : [])
            ->with('success', __('app.email_scenarios_saved'));
    }

    public function preview(
        EmailScenario $scenario,
        string $locale,
        EmailScenarioPreviewFactory $previewFactory,
    ): Response {
        abort_unless(in_array($locale, ['en', 'uk'], true), 404);

        $mail = $previewFactory->mail($scenario)->locale($locale);
        $html = $mail->render();

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'; img-src https: data:; style-src 'unsafe-inline'; font-src https: data:");
    }
}
