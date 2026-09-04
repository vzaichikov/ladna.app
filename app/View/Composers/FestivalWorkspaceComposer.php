<?php

namespace App\View\Composers;

use App\Enums\FestivalEditionStatus;
use App\Models\Account;
use App\Models\FestivalEdition;
use App\Models\FestivalJudgeAssignment;
use App\Support\Festivals\FestivalWorkspaceAccess;
use Illuminate\View\View;

class FestivalWorkspaceComposer
{
    public function __construct(private readonly FestivalWorkspaceAccess $workspaceAccess) {}

    public function compose(View $view): void
    {
        $route = request()->route();
        $account = $route?->parameter('account');
        $edition = $route?->parameter('festivalEdition');

        if (! $account instanceof Account || ! $edition instanceof FestivalEdition || $edition->account_id !== $account->id) {
            $view->with('festivalWorkspace', null);

            return;
        }

        $edition->loadMissing('series');
        $permissions = $this->workspaceAccess->permissions(request()->user(), $account, $edition);
        $hasJudgeAssignment = FestivalJudgeAssignment::query()
            ->where('festival_edition_id', $edition->id)
            ->where('user_id', request()->user()?->id)
            ->where('is_active', true)
            ->exists();
        $hasHeadJudgeAssignment = FestivalJudgeAssignment::query()
            ->where('festival_edition_id', $edition->id)
            ->where('user_id', request()->user()?->id)
            ->where('is_head_judge', true)
            ->where('is_active', true)
            ->exists();
        $active = $this->activeItem();

        if ($permissions['event_festival_staff']) {
            $view->with('festivalWorkspace', [
                'account' => $account,
                'edition' => $edition,
                'permissions' => $permissions,
                'active' => $active,
                'groups' => [[
                    'label' => __('app.festival_workspace_group_operations'),
                    'items' => [
                        $this->item('scanner', 'dashboard.accounts.festivals.scanner', 'festival_staff_scanner', 'qr-code', true, $active, $account, $edition),
                        $this->item('entrance', 'dashboard.accounts.festivals.attendance', 'festival_staff_entrance_monitor', 'scan-line', true, $active, $account, $edition),
                        $this->item('timeline', 'dashboard.accounts.festivals.timeline.index', 'festival_staff_live_timeline', 'timer', true, $active, $account, $edition),
                        $this->item('online-stream', 'dashboard.accounts.festivals.online-stream.edit', 'festival_staff_online_translation', 'video', true, $active, $account, $edition),
                    ],
                ]],
                'links' => [],
            ]);

            return;
        }

        $groups = [
            [
                'label' => __('app.festival_workspace_group_festival'),
                'items' => [
                    $this->item('overview', 'dashboard.accounts.festivals.show', 'festival_tab_overview', 'dashboard', true, $active, $account, $edition),
                    $this->item('settings-content', 'dashboard.accounts.festivals.settings.content', 'festival_content_media', 'settings', $permissions['manage'], $active, $account, $edition),
                    $this->item('users', 'dashboard.accounts.festivals.users.index', 'festival_users', 'users', $permissions['registrations'] || $permissions['manage'] || $permissions['finance'], $active, $account, $edition),
                ],
            ],
            [
                'label' => __('app.festival_workspace_group_participants'),
                'items' => [
                    $this->item('applications', 'dashboard.accounts.festivals.applications', 'festival_tab_applications', 'accounts', $permissions['registrations'] || $permissions['finance'], $active, $account, $edition),
                    $this->item('performances', 'dashboard.accounts.festivals.performances', 'festival_tab_performances', 'trophy', $permissions['registrations'], $active, $account, $edition),
                ],
            ],
            [
                'label' => __('app.festival_workspace_group_operations'),
                'items' => [
                    $this->item('program', 'dashboard.accounts.festivals.program', 'festival_tab_program', 'calendar-days', $permissions['schedule'], $active, $account, $edition),
                    $this->item('timeline', 'dashboard.accounts.festivals.timeline.index', 'festival_timeline_title', 'timer', $permissions['schedule'], $active, $account, $edition),
                    $this->item('tickets', 'dashboard.accounts.festivals.tickets', 'festival_tab_tickets_entrance', 'qr-code', $permissions['finance'] || $permissions['ticket_check_in'] || $permissions['door_staff'], $active, $account, $edition),
                    $this->item('entrance', 'dashboard.accounts.festivals.attendance', 'festival_entrance_monitor', 'scan-line', $permissions['door_staff'], $active, $account, $edition),
                    $this->item('online-stream', 'dashboard.accounts.festivals.online-stream.edit', 'festival_stream_settings', 'video', $permissions['finance'], $active, $account, $edition),
                    $this->item('communication', 'dashboard.accounts.festivals.communication', 'festival_tab_communication', 'bell', $permissions['manage'], $active, $account, $edition),
                ],
            ],
            [
                'label' => __('app.festival_workspace_group_judges'),
                'items' => [
                    $this->item('judging-judges', 'dashboard.accounts.festivals.judging.judges.index', 'festival_judges', 'users', $permissions['manage'], $active, $account, $edition),
                    $this->item('judging-criteria', 'dashboard.accounts.festivals.judging.criteria.index', 'festival_criteria', 'list-checks', $permissions['manage'], $active, $account, $edition),
                    $this->item('judging-score-sheets', 'dashboard.accounts.festivals.judging.score-sheets.index', 'festival_score_sheets', 'clipboard-check', $permissions['judging'], $active, $account, $edition),
                    $this->item('judging-battle-votes', 'dashboard.accounts.festivals.judging.battle-votes.index', 'festival_battle_voting', 'check-circle', $hasJudgeAssignment, $active, $account, $edition),
                    $this->item('judging-battles', 'dashboard.accounts.festivals.judging.battles.index', 'festival_battles', 'git-branch', $permissions['manage'], $active, $account, $edition),
                    $this->item('judging-results', 'dashboard.accounts.festivals.judging.results.index', 'festival_results', 'trophy', $permissions['manage'] || $hasHeadJudgeAssignment, $active, $account, $edition),
                    $this->item('judging-nominations', 'dashboard.accounts.festivals.judging.results.nominations.index', 'festival_nomination_assignments', 'award', $permissions['manage'] || $hasHeadJudgeAssignment, $active, $account, $edition),
                ],
            ],
            [
                'label' => __('app.festival_workspace_group_settings'),
                'items' => [
                    $this->item('settings', 'dashboard.accounts.festivals.settings', 'festival_settings_overview', 'settings', $permissions['manage'] || $permissions['schedule'] || $permissions['finance'], $active, $account, $edition),
                    $this->item('settings-stages', 'dashboard.accounts.festivals.settings.stages', 'festival_scenes', 'settings', $permissions['schedule'], $active, $account, $edition),
                    $this->item('settings-directions', 'dashboard.accounts.festivals.settings.directions', 'festival_taxonomy_directions', 'settings', $permissions['manage'], $active, $account, $edition),
                    $this->item('settings-nominations', 'dashboard.accounts.festivals.settings.nominations', 'festival_nominations', 'award', $permissions['manage'], $active, $account, $edition),
                    $this->item('settings-categories', 'dashboard.accounts.festivals.settings.categories', 'festival_categories', 'settings', $permissions['manage'], $active, $account, $edition),
                    $this->item('settings-workflows', 'dashboard.accounts.festivals.settings.workflows', 'festival_registration_workflows', 'settings', $permissions['manage'], $active, $account, $edition),
                    $this->item('settings-requirements', 'dashboard.accounts.festivals.settings.requirements', 'festival_registration_fields', 'settings', $permissions['manage'], $active, $account, $edition),
                    $this->item('settings-fees', 'dashboard.accounts.festivals.settings.fees', 'festival_fees', 'settings', $permissions['finance'], $active, $account, $edition),
                ],
            ],
        ];

        $links = [
            ...(in_array($edition->status, [FestivalEditionStatus::Published, FestivalEditionStatus::InProgress, FestivalEditionStatus::Completed], true) ? [[
                'label' => __('app.festival_public_page'),
                'description' => __('app.festival_public_page_sidebar_help'),
                'icon' => 'globe',
                'href' => route('public.festivals.show', [$account->slug, $edition->slug]),
                'external' => true,
            ]] : []),
            [
                'label' => __('app.festival_judge_cabinet'),
                'description' => __('app.festival_judge_cabinet_sidebar_help'),
                'icon' => 'clipboard-check',
                'href' => route('festival.portal.judge.dashboard', $account->slug),
                'external' => true,
            ],
        ];

        $view->with('festivalWorkspace', [
            'account' => $account,
            'edition' => $edition,
            'permissions' => $permissions,
            'active' => $active,
            'groups' => collect($groups)
                ->map(fn (array $group): array => [
                    ...$group,
                    'items' => array_values(array_filter($group['items'])),
                ])
                ->filter(fn (array $group): bool => $group['items'] !== [])
                ->values()
                ->all(),
            'links' => $links,
        ]);
    }

    private function activeItem(): string
    {
        return match (true) {
            request()->routeIs('dashboard.accounts.festivals.users.*') => 'users',
            request()->routeIs('dashboard.accounts.festivals.applications*') => 'applications',
            request()->routeIs('dashboard.accounts.festivals.performances*') => 'performances',
            request()->routeIs('dashboard.accounts.festivals.program') => 'program',
            request()->routeIs('dashboard.accounts.festivals.timeline.*') => 'timeline',
            request()->routeIs('dashboard.accounts.festivals.judging.judges.*') => 'judging-judges',
            request()->routeIs('dashboard.accounts.festivals.judging.criteria.*') => 'judging-criteria',
            request()->routeIs('dashboard.accounts.festivals.judging.score-sheets.*', 'dashboard.accounts.festivals.score-sheets.*') => 'judging-score-sheets',
            request()->routeIs('dashboard.accounts.festivals.judging.battle-votes.*') => 'judging-battle-votes',
            request()->routeIs('dashboard.accounts.festivals.judging.battles.*') => 'judging-battles',
            request()->routeIs('dashboard.accounts.festivals.judging.results.nominations.*') => 'judging-nominations',
            request()->routeIs('dashboard.accounts.festivals.judging.results.*') => 'judging-results',
            request()->routeIs('dashboard.accounts.festivals.scanner*') => ($permissions['event_festival_staff'] ?? false) ? 'scanner' : 'tickets',
            request()->routeIs('dashboard.accounts.festivals.attendance*', 'dashboard.accounts.festivals.entrance.*') => 'entrance',
            request()->routeIs('dashboard.accounts.festivals.tickets', 'dashboard.accounts.festivals.tickets.issue*', 'dashboard.accounts.festivals.admission-types.*', 'dashboard.accounts.festivals.promo-codes.*') => 'tickets',
            request()->routeIs('dashboard.accounts.festivals.online-stream.*') => 'online-stream',
            request()->routeIs('dashboard.accounts.festivals.communication*') => 'communication',
            request()->routeIs('dashboard.accounts.festivals.settings.stages', 'dashboard.accounts.festivals.stages.*') => 'settings-stages',
            request()->routeIs('dashboard.accounts.festivals.settings.directions', 'dashboard.accounts.festivals.directions.*') => 'settings-directions',
            request()->routeIs('dashboard.accounts.festivals.settings.nominations', 'dashboard.accounts.festivals.nominations.*') => 'settings-nominations',
            request()->routeIs('dashboard.accounts.festivals.settings.categories', 'dashboard.accounts.festivals.categories.*') => 'settings-categories',
            request()->routeIs('dashboard.accounts.festivals.settings.workflows', 'dashboard.accounts.festivals.workflows.*', 'dashboard.accounts.festivals.workflow-steps.*') => 'settings-workflows',
            request()->routeIs('dashboard.accounts.festivals.settings.requirements', 'dashboard.accounts.festivals.requirements.*') => 'settings-requirements',
            request()->routeIs('dashboard.accounts.festivals.settings.fees', 'dashboard.accounts.festivals.charge-definitions.*') => 'settings-fees',
            request()->routeIs('dashboard.accounts.festivals.settings.content', 'dashboard.accounts.festivals.settings.content.*', 'dashboard.accounts.festivals.content.*', 'dashboard.accounts.festivals.documents.*', 'dashboard.accounts.festivals.media.*') => 'settings-content',
            request()->routeIs('dashboard.accounts.festivals.settings', 'dashboard.accounts.festivals.edit') => 'settings',
            default => 'overview',
        };
    }

    /** @return array{key: string, label: string, icon: string, href: string, active: bool}|null */
    private function item(string $key, string $route, string $label, string $icon, bool $show, string $active, Account $account, FestivalEdition $edition): ?array
    {
        if (! $show) {
            return null;
        }

        return [
            'key' => $key,
            'label' => __('app.'.$label),
            'icon' => $icon,
            'href' => route($route, [$account, $edition]),
            'active' => $active === $key,
        ];
    }
}
