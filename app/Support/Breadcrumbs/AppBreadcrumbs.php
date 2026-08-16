<?php

namespace App\Support\Breadcrumbs;

use App\Enums\AccountRole;
use App\Enums\IntegrationCategory;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\FestivalEdition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

final class AppBreadcrumbs
{
    /**
     * @return array<int, array{label: string, href?: string}>
     */
    public function resolve(Request $request): array
    {
        $routeName = $request->route()?->getName();

        if (! is_string($routeName) || $routeName === '') {
            throw new LogicException('Authenticated app-layout routes must have a name before breadcrumbs can be resolved.');
        }

        $items = match (true) {
            $routeName === 'dashboard.index' => [$this->item(__('app.workspace'))],
            $routeName === 'dashboard.accounts.index' => [
                $this->item(__('app.workspace'), route('dashboard.index')),
                $this->item(__('app.accounts')),
            ],
            $routeName === 'dashboard.accounts.create' => [
                $this->item(__('app.workspace'), route('dashboard.index')),
                $this->item(__('app.accounts'), route('dashboard.accounts.index')),
                $this->item(__('app.breadcrumb_add_item', ['item' => __('app.account')])),
            ],
            Str::startsWith($routeName, 'dashboard.accounts.festivals.') => $this->festival($request, $routeName),
            Str::startsWith($routeName, 'dashboard.accounts.') => $this->account($request, $routeName),
            Str::startsWith($routeName, 'platform.') => $this->platform($request, $routeName),
            default => throw new LogicException("No app breadcrumb definition exists for route [{$routeName}]."),
        };

        $this->assertContract($items, $routeName);

        return $items;
    }

    /**
     * @return array<int, array{label: string, href?: string}>
     */
    private function account(Request $request, string $routeName): array
    {
        $account = $this->accountParameter($request);
        $isEventFestivalStaff = $this->isEventFestivalStaff($request, $account);
        $base = $request->user()?->isPlatformAdmin()
            ? [
                $this->item(__('app.platform_admin'), route('platform.index')),
                $this->item(__('app.accounts'), route('platform.accounts.index')),
                $this->item($account->name, route('platform.accounts.show', $account)),
            ]
            : ($isEventFestivalStaff ? [] : [
                $this->item(__('app.workspace'), route('dashboard.index')),
                $this->item($account->name, route('dashboard.accounts.show', $account)),
            ]);

        if ($routeName === 'dashboard.accounts.show') {
            $base[array_key_last($base)] = $this->item($account->name);

            return $base;
        }

        if ($routeName === 'dashboard.accounts.events.index') {
            return [...$base, $this->item(__('app.events'))];
        }

        if ($routeName === 'dashboard.accounts.events.create') {
            return [
                ...$base,
                $this->item(__('app.events'), route('dashboard.accounts.events.index', $account)),
                $this->item(__('app.breadcrumb_add_item', ['item' => __('app.event')])),
            ];
        }

        if (Str::startsWith($routeName, 'dashboard.accounts.events.')) {
            return $this->event($request, $routeName, $account, $base);
        }

        if (Str::startsWith($routeName, 'dashboard.accounts.reports.')) {
            return $this->report($request, $routeName, $account, $base);
        }

        if ($routeName === 'dashboard.accounts.integrations.show') {
            $category = $request->route('category');

            if (! $category instanceof IntegrationCategory) {
                throw new LogicException('The current breadcrumb route requires a bound IntegrationCategory.');
            }

            return [
                ...$base,
                $this->item(__('app.integrations'), route('dashboard.accounts.integrations.show', [$account, IntegrationCategory::Payment])),
                $this->item(__($category->labelKey())),
            ];
        }

        if ($routeName === 'dashboard.accounts.integrations.checkbox-logs.index') {
            return [
                ...$base,
                $this->item(__('app.integrations'), route('dashboard.accounts.integrations.show', [$account, IntegrationCategory::Payment])),
                $this->item(__('app.integration_category_fiscalization'), route('dashboard.accounts.integrations.show', [$account, IntegrationCategory::Fiscalization])),
                $this->item(__('app.checkbox_receipt_log')),
            ];
        }

        if ($routeName === 'dashboard.accounts.rooms.people-counter-mask.edit') {
            $room = $this->modelParameter($request, 'room');

            return [
                ...$base,
                $this->item(__('app.rooms'), route('dashboard.accounts.rooms.index', $account)),
                $this->item($this->modelLabel($room, __('app.room')), route('dashboard.accounts.rooms.edit', [$account, $room])),
                $this->item(__('app.people_counter_mask')),
            ];
        }

        if ($routeName === 'dashboard.accounts.trainers.private-timeframes.edit') {
            $trainer = $this->modelParameter($request, 'trainer');

            return [
                ...$base,
                $this->item(__('app.trainers'), route('dashboard.accounts.trainers.index', $account)),
                $this->item($this->modelLabel($trainer, __('app.trainer')), route('dashboard.accounts.trainers.edit', [$account, $trainer])),
                $this->item(__('app.trainer_private_timeframes')),
            ];
        }

        if ($routeName === 'dashboard.accounts.telegram-connections.index') {
            return [
                ...$base,
                $this->item(__('app.notification_settings'), route('dashboard.accounts.notification-settings.edit', [$account, 'tab' => 'telegram'])),
                $this->item(__('app.breadcrumb_telegram_connections')),
            ];
        }

        foreach ($this->accountResources() as $resource => $definition) {
            $prefix = 'dashboard.accounts.'.$resource;

            if ($routeName === $prefix.'.index' || $routeName === $prefix.'.create' || $routeName === $prefix.'.edit') {
                return $this->accountResource($request, $routeName, $account, $base, $resource, $definition);
            }
        }

        $directPages = $this->accountDirectPages();

        if (isset($directPages[$routeName])) {
            return [...$base, $this->item(__($directPages[$routeName]))];
        }

        throw new LogicException("No account breadcrumb definition exists for route [{$routeName}].");
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @return array<int, array{label: string, href?: string}>
     */
    private function event(Request $request, string $routeName, Account $account, array $base): array
    {
        $event = $this->modelParameter($request, 'event');
        $events = $this->item(__('app.events'), route('dashboard.accounts.events.index', $account));
        $eventLabel = $this->modelLabel($event, __('app.event'));
        $eventPage = $this->item($eventLabel, $this->isEventFestivalStaff($request, $account)
            ? route('dashboard.accounts.events.scanner', [$account, $event])
            : route('dashboard.accounts.events.edit', [$account, $event]));

        return match ($routeName) {
            'dashboard.accounts.events.edit' => [
                ...$base,
                $events,
                $this->item(__('app.breadcrumb_edit_item', ['item' => $eventLabel])),
            ],
            'dashboard.accounts.events.ticket-types.index' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.event_ticket_types')),
            ],
            'dashboard.accounts.events.ticket-types.create' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.event_ticket_types'), route('dashboard.accounts.events.ticket-types.index', [$account, $event])),
                $this->item(__('app.event_add_ticket_type')),
            ],
            'dashboard.accounts.events.ticket-types.edit' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.event_ticket_types'), route('dashboard.accounts.events.ticket-types.index', [$account, $event])),
                $this->item(__('app.breadcrumb_edit_item', [
                    'item' => $this->modelLabel($this->modelParameter($request, 'eventTicketType'), __('app.event_ticket_type')),
                ])),
            ],
            'dashboard.accounts.events.tickets.index' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.event_issued_tickets')),
            ],
            'dashboard.accounts.events.tickets.issue.create' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.event_issued_tickets'), route('dashboard.accounts.events.tickets.index', [$account, $event])),
                $this->item(__('app.event_issue_tickets')),
            ],
            'dashboard.accounts.events.orders.index' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.orders')),
            ],
            'dashboard.accounts.events.scanner' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.scanner')),
            ],
            'dashboard.accounts.events.attendance' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.event_attendance')),
            ],
            'dashboard.accounts.events.entrance.poster' => [
                ...$base,
                $events,
                $eventPage,
                $this->item(__('app.event_attendance'), route('dashboard.accounts.events.attendance', [$account, $event])),
                $this->item(__('app.entrance_payment_poster_title')),
            ],
            default => throw new LogicException("No event breadcrumb definition exists for route [{$routeName}]."),
        };
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @return array<int, array{label: string, href?: string}>
     */
    private function report(Request $request, string $routeName, Account $account, array $base): array
    {
        $reports = $this->item(__('app.reports'), route('dashboard.accounts.reports.index', $account));

        if ($routeName === 'dashboard.accounts.reports.index') {
            return [...$base, $this->item(__('app.reports'))];
        }

        if ($routeName === 'dashboard.accounts.reports.trainers.salary' || $routeName === 'dashboard.accounts.reports.trainers.private-lessons') {
            $trainer = $this->modelParameter($request, 'trainer');
            $trainerLabel = $this->modelLabel($trainer, __('app.trainer'));

            return [
                ...$base,
                $reports,
                $this->item(__('app.trainers'), route('dashboard.accounts.reports.trainers', $account)),
                $this->item($routeName === 'dashboard.accounts.reports.trainers.salary'
                    ? __('app.breadcrumb_trainer_salary_for', ['trainer' => $trainerLabel])
                    : __('app.breadcrumb_private_lessons_for', ['trainer' => $trainerLabel])),
            ];
        }

        $labels = [
            'dashboard.accounts.reports.earnings' => 'app.earnings_report',
            'dashboard.accounts.reports.financial' => 'app.financial_report',
            'dashboard.accounts.reports.people-counter' => 'app.people_counter_report',
            'dashboard.accounts.reports.rentals' => 'app.rental_report',
            'dashboard.accounts.reports.trainers' => 'app.trainers',
            'dashboard.accounts.reports.unknown-presence' => 'app.unknown_presence',
            'dashboard.accounts.reports.unpaid-class-payments' => 'app.unpaid_class_payments',
        ];

        if (! isset($labels[$routeName])) {
            throw new LogicException("No report breadcrumb definition exists for route [{$routeName}].");
        }

        return [...$base, $reports, $this->item(__($labels[$routeName]))];
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @param  array{label: string, parameter?: string}  $definition
     * @return array<int, array{label: string, href?: string}>
     */
    private function accountResource(Request $request, string $routeName, Account $account, array $base, string $resource, array $definition): array
    {
        $indexRoute = 'dashboard.accounts.'.$resource.'.index';
        $label = __($definition['label']);

        if ($routeName === $indexRoute) {
            return [...$base, $this->item($label)];
        }

        $resourceIndex = $this->item($label, route($indexRoute, $account));

        if ($routeName === 'dashboard.accounts.'.$resource.'.create') {
            return [...$base, $resourceIndex, $this->item(__('app.breadcrumb_add_item', ['item' => $label]))];
        }

        $model = $this->modelParameter($request, $definition['parameter'] ?? Str::singular(str_replace('-', '_', $resource)));

        return [
            ...$base,
            $resourceIndex,
            $this->item(__('app.breadcrumb_edit_item', ['item' => $this->modelLabel($model, $label)])),
        ];
    }

    /**
     * @return array<int, array{label: string, href?: string}>
     */
    private function festival(Request $request, string $routeName): array
    {
        $account = $this->accountParameter($request);
        $isEventFestivalStaff = $this->isEventFestivalStaff($request, $account);
        $festivalIndex = route('dashboard.accounts.festivals.index', $account);

        if ($routeName === 'dashboard.accounts.festivals.index') {
            return [$this->item(__('app.festivals'))];
        }

        if ($routeName === 'dashboard.accounts.festivals.create') {
            return [
                $this->item(__('app.festivals'), $festivalIndex),
                $this->item(__('app.festival_edition_create')),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.series.create') {
            return [
                $this->item(__('app.festivals'), $festivalIndex),
                $this->item(__('app.festival_series_create')),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.series.edit') {
            $series = $this->modelParameter($request, 'festivalSeries');

            return [
                $this->item(__('app.festivals'), $festivalIndex),
                $this->item(__('app.breadcrumb_edit_item', ['item' => $this->modelLabel($series, __('app.festival_series'))])),
            ];
        }

        $edition = $this->editionParameter($request);
        $base = [
            $this->item(__('app.festivals'), $festivalIndex),
            $this->item($edition->title, $isEventFestivalStaff
                ? route('dashboard.accounts.festivals.scanner', [$account, $edition])
                : route('dashboard.accounts.festivals.show', [$account, $edition])),
        ];

        if ($isEventFestivalStaff) {
            return match ($routeName) {
                'dashboard.accounts.festivals.scanner' => [...$base, $this->item(__('app.festival_staff_scanner'))],
                'dashboard.accounts.festivals.attendance' => [...$base, $this->item(__('app.festival_staff_entrance_monitor'))],
                'dashboard.accounts.festivals.entrance.poster' => [
                    ...$base,
                    $this->item(__('app.festival_staff_entrance_monitor'), route('dashboard.accounts.festivals.attendance', [$account, $edition])),
                    $this->item(__('app.entrance_payment_poster_title')),
                ],
                'dashboard.accounts.festivals.timeline.show' => [
                    ...$base,
                    $this->item(__('app.festival_staff_live_timeline'), route('dashboard.accounts.festivals.timeline.index', [$account, $edition])),
                    $this->item($this->modelLabel($this->modelParameter($request, 'festivalStage'), __('app.festival_scene'))),
                ],
                'dashboard.accounts.festivals.online-stream.edit' => [...$base, $this->item(__('app.festival_staff_online_translation'))],
                default => throw new LogicException("No Event/Festival staff breadcrumb definition exists for route [{$routeName}]."),
            };
        }

        if ($routeName === 'dashboard.accounts.festivals.show') {
            $base[1] = $this->item($edition->title);

            return $base;
        }

        if (Str::startsWith($routeName, 'dashboard.accounts.festivals.users.')) {
            return $this->festivalUsers($request, $routeName, $account, $edition, $base);
        }

        if (Str::startsWith($routeName, 'dashboard.accounts.festivals.judging.')) {
            return $this->festivalJudging($request, $routeName, $account, $edition, $base);
        }

        if (Str::startsWith($routeName, 'dashboard.accounts.festivals.admission-types.')) {
            $tickets = $this->item(__('app.festival_tickets'), route('dashboard.accounts.festivals.tickets', [$account, $edition, 'tab' => 'types']));

            if ($routeName === 'dashboard.accounts.festivals.admission-types.create') {
                return [...$base, $tickets, $this->item(__('app.festival_add_admission_type'))];
            }

            $admissionType = $this->modelParameter($request, 'festivalAdmissionType');

            return [...$base, $tickets, $this->item(__('app.breadcrumb_edit_item', ['item' => $this->modelLabel($admissionType, __('app.festival_ticket_type'))]))];
        }

        if ($routeName === 'dashboard.accounts.festivals.applications.media-report') {
            return [
                ...$base,
                $this->item(__('app.festival_tab_applications'), route('dashboard.accounts.festivals.applications', [$account, $edition])),
                $this->item(__('app.festival_media_report')),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.applications.show') {
            $entry = $this->modelParameter($request, 'festivalEntry');

            return [
                ...$base,
                $this->item(__('app.festival_tab_applications'), route('dashboard.accounts.festivals.applications', [$account, $edition])),
                $this->item($this->modelLabel($entry, __('app.festival_application'))),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.performances.show') {
            $entry = $this->modelParameter($request, 'festivalEntry');

            return [
                ...$base,
                $this->item(__('app.festival_tab_performances'), route('dashboard.accounts.festivals.performances', [$account, $edition])),
                $this->item($this->modelLabel($entry, __('app.festival_performance'))),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.timeline.show') {
            $stage = $this->modelParameter($request, 'festivalStage');

            return [
                ...$base,
                $this->item(__('app.festival_timeline_title'), route('dashboard.accounts.festivals.timeline.index', [$account, $edition])),
                $this->item($this->modelLabel($stage, __('app.festival_scene'))),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.tickets.issue') {
            return [
                ...$base,
                $this->item(__('app.festival_tickets'), route('dashboard.accounts.festivals.tickets', [$account, $edition])),
                $this->item(__('app.festival_issue_tickets')),
            ];
        }

        $workspaceLabels = [
            'dashboard.accounts.festivals.applications' => 'app.festival_tab_applications',
            'dashboard.accounts.festivals.performances' => 'app.festival_tab_performances',
            'dashboard.accounts.festivals.program' => 'app.festival_tab_program',
            'dashboard.accounts.festivals.tickets' => 'app.festival_tickets',
            'dashboard.accounts.festivals.attendance' => 'app.festival_entrance_monitor',
            'dashboard.accounts.festivals.online-stream.edit' => 'app.festival_stream_settings',
            'dashboard.accounts.festivals.online-stream.update' => 'app.festival_stream_settings',
            'dashboard.accounts.festivals.online-stream.reset-leases' => 'app.festival_stream_settings',
            'dashboard.accounts.festivals.communication' => 'app.festival_tab_communication',
        ];

        if (isset($workspaceLabels[$routeName])) {
            return [...$base, $this->item(__($workspaceLabels[$routeName]))];
        }

        if ($routeName === 'dashboard.accounts.festivals.scanner') {
            return [
                ...$base,
                $this->item(__('app.festival_tickets'), route('dashboard.accounts.festivals.tickets', [$account, $edition])),
                $this->item(__('app.scanner')),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.entrance.poster') {
            return [
                ...$base,
                $this->item(__('app.festival_entrance_monitor'), route('dashboard.accounts.festivals.attendance', [$account, $edition])),
                $this->item(__('app.entrance_payment_poster_title')),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.edit') {
            return [
                ...$base,
                $this->item(__('app.festival_tab_settings'), route('dashboard.accounts.festivals.settings', [$account, $edition])),
                $this->item(__('app.festival_edit_edition_details')),
            ];
        }

        return $this->festivalSettings($request, $routeName, $account, $edition, $base);
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @return array<int, array{label: string, href?: string}>
     */
    private function festivalUsers(Request $request, string $routeName, Account $account, FestivalEdition $edition, array $base): array
    {
        $indexRoute = route('dashboard.accounts.festivals.users.index', [$account, $edition]);
        $users = $this->item(__('app.festival_users'), $indexRoute);

        if ($routeName === 'dashboard.accounts.festivals.users.index') {
            return [...$base, $this->item(__('app.festival_users'))];
        }

        if ($routeName === 'dashboard.accounts.festivals.users.create') {
            return [...$base, $users, $this->item(__('app.festival_add_user'))];
        }

        $portalUser = $this->modelParameter($request, 'festivalPortalUser');
        $portalUserItem = $this->item(
            $this->modelLabel($portalUser, __('app.festival_user')),
            route('dashboard.accounts.festivals.users.edit', [$account, $edition, $portalUser]),
        );

        if ($routeName === 'dashboard.accounts.festivals.users.edit') {
            return [...$base, $users, $this->item($this->modelLabel($portalUser, __('app.festival_user')))];
        }

        if ($routeName === 'dashboard.accounts.festivals.users.participants.create') {
            return [...$base, $users, $portalUserItem, $this->item(__('app.festival_add_participant'))];
        }

        $participant = $this->modelParameter($request, 'festivalParticipant');
        $participantLabel = $this->modelLabel($participant, __('app.festival_participant'));

        return match ($routeName) {
            'dashboard.accounts.festivals.users.participants.edit' => [...$base, $users, $portalUserItem, $this->item(__('app.breadcrumb_edit_item', ['item' => $participantLabel]))],
            'dashboard.accounts.festivals.users.participants.archive' => [...$base, $users, $portalUserItem, $this->item(__('app.archive').' '.$participantLabel)],
            default => throw new LogicException("No Festival user breadcrumb definition exists for route [{$routeName}]."),
        };
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @return array<int, array{label: string, href?: string}>
     */
    private function festivalJudging(Request $request, string $routeName, Account $account, FestivalEdition $edition, array $base): array
    {
        if ($routeName === 'dashboard.accounts.festivals.judging.index') {
            return [...$base, $this->item(__('app.festival_workspace_group_judges'))];
        }

        $definitions = [
            'judges' => [
                'index' => 'dashboard.accounts.festivals.judging.judges.index',
                'label' => 'app.festival_judges',
                'item' => 'app.festival_judge',
                'parameter' => 'festivalJudgeAssignment',
            ],
            'criteria' => [
                'index' => 'dashboard.accounts.festivals.judging.criteria.index',
                'label' => 'app.festival_criteria',
                'item' => 'app.festival_rubric',
                'parameter' => 'festivalRubric',
            ],
        ];

        foreach ($definitions as $definition) {
            $indexRoute = $definition['index'];
            $routePrefix = Str::beforeLast($indexRoute, '.index');
            $label = __($definition['label']);

            if ($routeName === $indexRoute) {
                return [...$base, $this->item($label)];
            }

            $index = $this->item($label, route($indexRoute, [$account, $edition]));

            if ($routeName === $routePrefix.'.create') {
                return [...$base, $index, $this->item(__('app.breadcrumb_add_item', ['item' => __($definition['item'])]))];
            }

            if ($routeName === $routePrefix.'.edit') {
                $model = $this->modelParameter($request, $definition['parameter']);

                return [...$base, $index, $this->item(__('app.breadcrumb_edit_item', ['item' => $this->modelLabel($model, __($definition['item']))]))];
            }
        }

        if ($routeName === 'dashboard.accounts.festivals.judging.score-sheets.index') {
            return [...$base, $this->item(__('app.festival_score_sheets'))];
        }

        if ($routeName === 'dashboard.accounts.festivals.judging.score-sheets.edit') {
            return [
                ...$base,
                $this->item(__('app.festival_score_sheets'), route('dashboard.accounts.festivals.judging.score-sheets.index', [$account, $edition])),
                $this->item(__('app.festival_score_sheet')),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.judging.results.index') {
            return [...$base, $this->item(__('app.festival_results'))];
        }

        if ($routeName === 'dashboard.accounts.festivals.judging.results.preview') {
            $category = $this->modelParameter($request, 'festivalCategory');

            return [
                ...$base,
                $this->item(__('app.festival_results'), route('dashboard.accounts.festivals.judging.results.index', [$account, $edition])),
                $this->item($this->modelLabel($category, __('app.festival_category'))),
            ];
        }

        if ($routeName === 'dashboard.accounts.festivals.judging.battles.index') {
            return [...$base, $this->item(__('app.festival_battles'))];
        }

        if ($routeName === 'dashboard.accounts.festivals.judging.battle-votes.index') {
            return [...$base, $this->item(__('app.festival_battle_voting'))];
        }

        throw new LogicException("No Festival judging breadcrumb definition exists for route [{$routeName}].");
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @return array<int, array{label: string, href?: string}>
     */
    private function festivalSettings(Request $request, string $routeName, Account $account, FestivalEdition $edition, array $base): array
    {
        $settingsRoute = route('dashboard.accounts.festivals.settings', [$account, $edition]);

        if ($routeName === 'dashboard.accounts.festivals.settings') {
            return [...$base, $this->item(__('app.festival_tab_settings'))];
        }

        $settings = $this->item(__('app.festival_tab_settings'), $settingsRoute);
        $definitions = [
            'stages' => [
                'index' => 'dashboard.accounts.festivals.settings.stages',
                'label' => 'app.festival_scenes',
                'item' => 'app.festival_scene',
                'create' => 'dashboard.accounts.festivals.stages.create',
                'edit' => 'dashboard.accounts.festivals.stages.edit',
                'parameter' => 'festivalStage',
            ],
            'directions' => [
                'index' => 'dashboard.accounts.festivals.settings.directions',
                'label' => 'app.festival_taxonomy_directions',
                'item' => 'app.festival_taxonomy_direction',
                'create' => 'dashboard.accounts.festivals.directions.create',
                'edit' => 'dashboard.accounts.festivals.directions.edit',
                'parameter' => 'festivalDirection',
            ],
            'categories' => [
                'index' => 'dashboard.accounts.festivals.settings.categories',
                'label' => 'app.festival_categories',
                'item' => 'app.festival_category',
                'create' => 'dashboard.accounts.festivals.categories.create',
                'edit' => 'dashboard.accounts.festivals.categories.edit',
                'parameter' => 'festivalCategory',
            ],
            'workflows' => [
                'index' => 'dashboard.accounts.festivals.settings.workflows',
                'label' => 'app.festival_registration_workflows',
                'item' => 'app.festival_registration_workflow',
                'create' => 'dashboard.accounts.festivals.workflows.create',
                'edit' => 'dashboard.accounts.festivals.workflows.edit',
                'parameter' => 'festivalWorkflow',
            ],
            'requirements' => [
                'index' => 'dashboard.accounts.festivals.settings.requirements',
                'label' => 'app.festival_registration_fields',
                'item' => 'app.festival_registration_field',
                'create' => 'dashboard.accounts.festivals.requirements.create',
                'edit' => 'dashboard.accounts.festivals.requirements.edit',
                'parameter' => 'festivalRequirementDefinition',
            ],
            'fees' => [
                'index' => 'dashboard.accounts.festivals.settings.fees',
                'label' => 'app.festival_fees',
                'item' => 'app.festival_fee',
                'create' => 'dashboard.accounts.festivals.charge-definitions.create',
                'edit' => 'dashboard.accounts.festivals.charge-definitions.edit',
                'parameter' => 'festivalChargeDefinition',
            ],
        ];

        foreach ($definitions as $definition) {
            if (in_array($routeName, [$definition['index'], $definition['create'], $definition['edit']], true)) {
                return $this->festivalSettingsResource($request, $routeName, $account, $edition, $base, $settings, $definition);
            }
        }

        if (Str::startsWith($routeName, 'dashboard.accounts.festivals.workflow-steps.')) {
            return $this->festivalWorkflowSteps($request, $routeName, $account, $edition, $base, $settings);
        }

        if ($routeName === 'dashboard.accounts.festivals.settings.content') {
            return [...$base, $settings, $this->item(__('app.festival_content_media'))];
        }

        return $this->festivalContentResource($request, $routeName, $account, $edition, $base, $settings);
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @param  array{label: string, href?: string}  $settings
     * @param  array{index: string, label: string, item: string, create: string, edit: string, parameter: string}  $definition
     * @return array<int, array{label: string, href?: string}>
     */
    private function festivalSettingsResource(Request $request, string $routeName, Account $account, FestivalEdition $edition, array $base, array $settings, array $definition): array
    {
        $label = __($definition['label']);
        $itemLabel = __($definition['item']);

        if ($routeName === $definition['index']) {
            return [...$base, $settings, $this->item($label)];
        }

        $index = $this->item($label, route($definition['index'], [$account, $edition]));

        if ($routeName === $definition['create']) {
            return [...$base, $settings, $index, $this->item(__('app.breadcrumb_add_item', ['item' => $itemLabel]))];
        }

        $model = $this->modelParameter($request, $definition['parameter']);

        return [
            ...$base,
            $settings,
            $index,
            $this->item(__('app.breadcrumb_edit_item', ['item' => $this->modelLabel($model, $itemLabel)])),
        ];
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @param  array{label: string, href?: string}  $settings
     * @return array<int, array{label: string, href?: string}>
     */
    private function festivalWorkflowSteps(Request $request, string $routeName, Account $account, FestivalEdition $edition, array $base, array $settings): array
    {
        $workflow = $this->modelParameter($request, 'festivalWorkflow');
        $workflowsRoute = route('dashboard.accounts.festivals.settings.workflows', [$account, $edition]);
        $stepsRoute = route('dashboard.accounts.festivals.workflow-steps.index', [$account, $edition, $workflow]);
        $workflowLabel = $this->modelLabel($workflow, __('app.festival_registration_workflow'));
        $trail = [
            ...$base,
            $settings,
            $this->item(__('app.festival_registration_workflows'), $workflowsRoute),
        ];

        return match ($routeName) {
            'dashboard.accounts.festivals.workflow-steps.index' => [
                ...$trail,
                $this->item($workflowLabel, route('dashboard.accounts.festivals.workflows.edit', [$account, $edition, $workflow])),
                $this->item(__('app.festival_workflow_steps')),
            ],
            'dashboard.accounts.festivals.workflow-steps.create' => [
                ...$trail,
                $this->item($workflowLabel, $stepsRoute),
                $this->item(__('app.festival_add_workflow_step')),
            ],
            'dashboard.accounts.festivals.workflow-steps.edit' => [
                ...$trail,
                $this->item($workflowLabel, $stepsRoute),
                $this->item(__('app.breadcrumb_edit_item', [
                    'item' => $this->modelLabel($this->modelParameter($request, 'festivalWorkflowStep'), __('app.festival_workflow_step')),
                ])),
            ],
            default => throw new LogicException("No workflow-step breadcrumb definition exists for route [{$routeName}]."),
        };
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @param  array{label: string, href?: string}  $settings
     * @return array<int, array{label: string, href?: string}>
     */
    private function festivalContentResource(Request $request, string $routeName, Account $account, FestivalEdition $edition, array $base, array $settings): array
    {
        $contentRoute = route('dashboard.accounts.festivals.settings.content', [$account, $edition]);
        $content = $this->item(__('app.festival_content_media'), $contentRoute);
        $definitions = [
            [
                'index' => 'dashboard.accounts.festivals.settings.content.sections',
                'label' => 'app.festival_content_sections',
                'item' => 'app.festival_content_section',
                'create' => 'dashboard.accounts.festivals.content.create',
                'edit' => 'dashboard.accounts.festivals.content.edit',
                'parameter' => 'festivalContentSection',
            ],
            [
                'index' => 'dashboard.accounts.festivals.settings.content.documents',
                'label' => 'app.festival_documents',
                'item' => 'app.festival_document',
                'create' => 'dashboard.accounts.festivals.documents.create',
                'edit' => 'dashboard.accounts.festivals.documents.edit',
                'parameter' => 'festivalDocument',
            ],
            [
                'index' => 'dashboard.accounts.festivals.settings.content.media',
                'label' => 'app.festival_media',
                'item' => 'app.festival_media_item',
                'create' => 'dashboard.accounts.festivals.media.create',
                'edit' => 'dashboard.accounts.festivals.media.edit',
                'parameter' => 'festivalMedia',
            ],
        ];

        foreach ($definitions as $definition) {
            if (! in_array($routeName, [$definition['index'], $definition['create'], $definition['edit']], true)) {
                continue;
            }

            $label = __($definition['label']);
            $itemLabel = __($definition['item']);

            if ($routeName === $definition['index']) {
                return [...$base, $settings, $content, $this->item($label)];
            }

            $index = $this->item($label, route($definition['index'], [$account, $edition]));

            if ($routeName === $definition['create']) {
                return [...$base, $settings, $content, $index, $this->item(__('app.breadcrumb_add_item', ['item' => $itemLabel]))];
            }

            $model = $this->modelParameter($request, $definition['parameter']);

            return [
                ...$base,
                $settings,
                $content,
                $index,
                $this->item(__('app.breadcrumb_edit_item', ['item' => $this->modelLabel($model, $itemLabel)])),
            ];
        }

        throw new LogicException("No Festival content breadcrumb definition exists for route [{$routeName}].");
    }

    /**
     * @return array<int, array{label: string, href?: string}>
     */
    private function platform(Request $request, string $routeName): array
    {
        if ($routeName === 'platform.index') {
            return [$this->item(__('app.platform_admin'))];
        }

        $base = [$this->item(__('app.platform_admin'), route('platform.index'))];

        if ($routeName === 'platform.accounts.index') {
            return [...$base, $this->item(__('app.accounts'))];
        }

        if ($routeName === 'platform.accounts.create') {
            return [
                ...$base,
                $this->item(__('app.accounts'), route('platform.accounts.index')),
                $this->item(__('app.breadcrumb_add_item', ['item' => __('app.account')])),
            ];
        }

        if (Str::startsWith($routeName, 'platform.accounts.')) {
            $account = $this->accountParameter($request);
            $accounts = $this->item(__('app.accounts'), route('platform.accounts.index'));

            if ($routeName === 'platform.accounts.show') {
                return [...$base, $accounts, $this->item($account->name)];
            }

            $accountItem = $this->item($account->name, route('platform.accounts.show', $account));
            $labels = [
                'platform.accounts.edit' => 'app.edit',
                'platform.accounts.studio-possibilities.edit' => 'app.studio_capabilities_settings',
                'platform.accounts.sms-account.show' => 'app.sms_account',
            ];

            if (! isset($labels[$routeName])) {
                throw new LogicException("No platform-account breadcrumb definition exists for route [{$routeName}].");
            }

            return [...$base, $accounts, $accountItem, $this->item(__($labels[$routeName]))];
        }

        if (Str::startsWith($routeName, 'platform.subscription-plans.')) {
            return $this->platformSubscriptions($request, $routeName, $base);
        }

        $directPages = [
            'platform.account.edit' => 'app.profile',
            'platform.ai-usage.index' => 'app.ai_usage_statistics',
            'platform.customer-notifications.index' => 'app.customer_notifications',
            'platform.email-deliveries.index' => 'app.email_deliveries',
            'platform.email-scenarios.index' => 'app.email_scenarios',
            'platform.integrations.index' => 'app.integrations',
            'platform.payments.index' => 'app.payments',
            'platform.scheduled-tasks.index' => 'app.scheduled_tasks',
            'platform.settings.edit' => 'app.system_settings',
            'platform.sms-deliveries.index' => 'app.sms_delivery_log',
            'platform.sms-payments.index' => 'app.sms_payments',
            'platform.telegram-support.index' => 'app.telegram_support',
        ];

        if (! isset($directPages[$routeName])) {
            throw new LogicException("No platform breadcrumb definition exists for route [{$routeName}].");
        }

        return [...$base, $this->item(__($directPages[$routeName]))];
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $base
     * @return array<int, array{label: string, href?: string}>
     */
    private function platformSubscriptions(Request $request, string $routeName, array $base): array
    {
        $indexRoute = 'platform.subscription-plans.index';
        $plansLabel = __('app.subscription_plans');

        if ($routeName === $indexRoute) {
            return [...$base, $this->item($plansLabel)];
        }

        $plans = $this->item($plansLabel, route($indexRoute));

        if ($routeName === 'platform.subscription-plans.create') {
            return [...$base, $plans, $this->item(__('app.breadcrumb_add_item', ['item' => __('app.subscription_plan')]))];
        }

        $plan = $this->modelParameter($request, $request->route('subscription_plan') ? 'subscription_plan' : 'subscriptionPlan');
        $planLabel = $this->modelLabel($plan, __('app.subscription_plan'));

        if ($routeName === 'platform.subscription-plans.edit') {
            return [...$base, $plans, $this->item(__('app.breadcrumb_edit_item', ['item' => $planLabel]))];
        }

        $planItem = $this->item($planLabel, route('platform.subscription-plans.edit', $plan));
        $versionsRoute = route('platform.subscription-plans.price-versions.index', $plan);

        if ($routeName === 'platform.subscription-plans.price-versions.index') {
            return [...$base, $plans, $planItem, $this->item(__('app.breadcrumb_price_versions'))];
        }

        $versions = $this->item(__('app.breadcrumb_price_versions'), $versionsRoute);

        if ($routeName === 'platform.subscription-plans.price-versions.create') {
            return [...$base, $plans, $planItem, $versions, $this->item(__('app.breadcrumb_add_item', ['item' => __('app.breadcrumb_price_version')]))];
        }

        $version = $this->modelParameter($request, 'priceVersion');
        $versionLabel = $this->modelLabel($version, __('app.breadcrumb_price_version'));

        return match ($routeName) {
            'platform.subscription-plans.price-versions.edit' => [...$base, $plans, $planItem, $versions, $this->item(__('app.breadcrumb_edit_item', ['item' => $versionLabel]))],
            'platform.subscription-plans.price-versions.preview' => [...$base, $plans, $planItem, $versions, $this->item($versionLabel, route('platform.subscription-plans.price-versions.edit', [$plan, $version])), $this->item(__('app.preview'))],
            default => throw new LogicException("No subscription breadcrumb definition exists for route [{$routeName}]."),
        };
    }

    /**
     * @return array<string, array{label: string, parameter?: string}>
     */
    private function accountResources(): array
    {
        return [
            'activity-directions' => ['label' => 'app.activity_directions', 'parameter' => 'activity_direction'],
            'class-pass-plans' => ['label' => 'app.class_pass_plans', 'parameter' => 'class_pass_plan'],
            'class-pass-segments' => ['label' => 'app.class_pass_segments', 'parameter' => 'class_pass_segment'],
            'class-types' => ['label' => 'app.class_types', 'parameter' => 'class_type'],
            'group-classes' => ['label' => 'app.group_classes', 'parameter' => 'class_type'],
            'internal-classes' => ['label' => 'app.internal_classes', 'parameter' => 'class_type'],
            'private-lessons' => ['label' => 'app.private_lessons', 'parameter' => 'class_type'],
            'room-rentals' => ['label' => 'app.room_rentals', 'parameter' => 'class_type'],
            'locations' => ['label' => 'app.locations', 'parameter' => 'location'],
            'rooms' => ['label' => 'app.rooms', 'parameter' => 'room'],
            'service-rooms' => ['label' => 'app.service_rooms', 'parameter' => 'service_room'],
            'schedule-series' => ['label' => 'app.schedule_series', 'parameter' => 'schedule_series'],
            'trainers' => ['label' => 'app.trainers', 'parameter' => 'trainer'],
            'customers' => ['label' => 'app.customers', 'parameter' => 'customer'],
            'customer-class-passes' => ['label' => 'app.customer_class_passes', 'parameter' => 'customerClassPass'],
            'event-festival-staff' => ['label' => 'app.event_festival_staff', 'parameter' => 'membership'],
            'salary-models' => ['label' => 'app.salary_models', 'parameter' => 'salaryModel'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function accountDirectPages(): array
    {
        return [
            'dashboard.accounts.edit' => 'app.account_settings',
            'dashboard.accounts.activity-logs.index' => 'app.breadcrumb_activity_log',
            'dashboard.accounts.brand.edit' => 'app.breadcrumb_brand',
            'dashboard.accounts.cameras.index' => 'app.cameras',
            'dashboard.accounts.cash.index' => 'app.cash_overview',
            'dashboard.accounts.customer-class-passes.index' => 'app.customer_class_passes',
            'dashboard.accounts.customer-notification-logs.index' => 'app.customer_notifications',
            'dashboard.accounts.expenses.index' => 'app.operational_expenses',
            'dashboard.accounts.general-settings.edit' => 'app.studio_settings',
            'dashboard.accounts.integrations.index' => 'app.integrations',
            'dashboard.accounts.notification-settings.edit' => 'app.notification_settings',
            'dashboard.accounts.owner-profile.edit' => 'app.profile',
            'dashboard.accounts.payments.index' => 'app.payments',
            'dashboard.accounts.payroll.index' => 'app.breadcrumb_payroll',
            'dashboard.accounts.qr-links.show' => 'app.breadcrumb_qr_links',
            'dashboard.accounts.scheduled-classes.index' => 'app.generated_classes',
            'dashboard.accounts.scheduled-classes-history.index' => 'app.scheduled_classes_history',
            'dashboard.accounts.sms-account.show' => 'app.sms_account',
            'dashboard.accounts.studio-settings.index' => 'app.studio_settings',
            'dashboard.accounts.tariff-payments.show' => 'app.tariff_payments',
            'dashboard.accounts.trainer-private-timeframes.mine' => 'app.trainer_private_timeframes',
            'dashboard.accounts.trainer-telegram-alert-logs.index' => 'app.breadcrumb_trainer_alerts',
            'dashboard.accounts.trainer-types.index' => 'app.trainer_types',
            'dashboard.accounts.website-leads.index' => 'app.website_leads',
        ];
    }

    private function accountParameter(Request $request): Account
    {
        $account = $request->route('account');

        if (! $account instanceof Account) {
            throw new LogicException('The current breadcrumb route requires a bound Account model.');
        }

        return $account;
    }

    private function isEventFestivalStaff(Request $request, Account $account): bool
    {
        $user = $request->user();

        return $user instanceof User
            && $account->membershipFor($user)?->role === AccountRole::EventFestivalStaff;
    }

    private function editionParameter(Request $request): FestivalEdition
    {
        $edition = $request->route('festivalEdition');

        if (! $edition instanceof FestivalEdition) {
            throw new LogicException('The current breadcrumb route requires a bound FestivalEdition model.');
        }

        return $edition;
    }

    private function modelParameter(Request $request, string $parameter): Model
    {
        $model = $request->route($parameter);

        if (! $model instanceof Model) {
            throw new LogicException("The current breadcrumb route requires a bound model in parameter [{$parameter}].");
        }

        return $model;
    }

    private function modelLabel(Model $model, string $fallback): string
    {
        if ($model instanceof AccountMembership) {
            $name = $model->relationLoaded('user')
                ? $model->user?->name
                : $model->user()->value('name');

            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        foreach (['name', 'title', 'display_name', 'entry_name', 'code', 'order_id'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        if (method_exists($model, 'displayName')) {
            $displayName = $model->displayName();

            if (is_string($displayName) && trim($displayName) !== '') {
                return $displayName;
            }
        }

        return $fallback.' #'.$model->getKey();
    }

    /**
     * @param  array<int, array{label: string, href?: string}>  $items
     */
    private function assertContract(array $items, string $routeName): void
    {
        if ($items === []) {
            throw new LogicException("Breadcrumb definition for route [{$routeName}] cannot be empty.");
        }

        $lastIndex = array_key_last($items);

        foreach ($items as $index => $item) {
            if (! isset($item['label']) || trim($item['label']) === '') {
                throw new LogicException("Breadcrumb item [{$index}] for route [{$routeName}] requires a label.");
            }

            if ($index !== $lastIndex && ! filled($item['href'] ?? null)) {
                throw new LogicException("Breadcrumb ancestor [{$index}] for route [{$routeName}] must be clickable.");
            }

            if ($index === $lastIndex && isset($item['href'])) {
                throw new LogicException("The current breadcrumb item for route [{$routeName}] must be plain text.");
            }
        }
    }

    /**
     * @return array{label: string, href?: string}
     */
    private function item(string $label, ?string $href = null): array
    {
        return $href === null ? ['label' => $label] : ['label' => $label, 'href' => $href];
    }
}
