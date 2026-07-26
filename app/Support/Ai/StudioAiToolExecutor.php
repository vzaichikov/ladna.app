<?php

namespace App\Support\Ai;

use App\Enums\AccountApiTokenAbility;
use App\Enums\McpToolInvocationStatus;
use App\Enums\StudioPermission;
use App\Models\Account;
use App\Models\AiConversation;
use App\Models\AiConversationMessage;
use App\Models\McpToolInvocation;
use App\Models\User;
use App\Support\CustomerBookingLedgerInvestigation;
use App\Support\CustomerInvestigationSearch;
use App\Support\LadnaBusinessLogicReference;
use App\Support\OwnerHelpIndex;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class StudioAiToolExecutor
{
    private const SearchOwnerHelp = 'search_owner_help';

    private const GetOwnerHelpPage = 'get_owner_help_page';

    private const SearchCustomers = 'search_customers';

    private const InvestigateCustomerBookingLedger = 'investigate_customer_booking_ledger';

    private const GetBusinessLogicReference = 'get_business_logic_reference';

    public function __construct(
        private readonly CustomerInvestigationSearch $customerSearch,
        private readonly CustomerBookingLedgerInvestigation $bookingLedgerInvestigation,
        private readonly LadnaBusinessLogicReference $businessLogicReference,
        private readonly StudioAiLedgerEvidencePresenter $ledgerEvidencePresenter,
        private readonly OwnerHelpIndex $helpIndex,
    ) {}

    public function helpAvailableFor(Account $account, ?User $actorUser): bool
    {
        return $actorUser !== null
            && $account->userCan($actorUser, StudioPermission::InteractWithTelegramBot);
    }

    public function investigationAvailableFor(Account $account, ?User $actorUser): bool
    {
        return $this->helpAvailableFor($account, $actorUser)
            && $account->userCan($actorUser, StudioPermission::ManageCustomerClassPasses);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function definitions(Account $account, ?User $actorUser): array
    {
        $definitions = [];

        if ($this->helpAvailableFor($account, $actorUser)) {
            $definitions = [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => self::SearchOwnerHelp,
                        'description' => 'Search curated Ladna owner help. Turn the owner’s noisy, misspelled, or conversational wording into a concise canonical search query before calling this tool. Use it before answering interface, workflow, settings, or business-process questions.',
                        'parameters' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'query' => [
                                    'type' => 'string',
                                    'description' => 'Concise canonical help query without greetings, filler, or misspellings.',
                                ],
                                'limit' => [
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'maximum' => 5,
                                    'default' => 5,
                                ],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                ],
                [
                    'type' => 'function',
                    'function' => [
                        'name' => self::GetOwnerHelpPage,
                        'description' => 'Read the complete curated Ladna owner help page selected from search_owner_help results before answering the owner.',
                        'parameters' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'slug' => [
                                    'type' => 'string',
                                    'description' => 'Exact page slug returned by search_owner_help.',
                                ],
                            ],
                            'required' => ['slug'],
                        ],
                    ],
                ],
            ];
        }

        if (! $this->investigationAvailableFor($account, $actorUser)) {
            return $definitions;
        }

        return [
            ...$definitions,
            [
                'type' => 'function',
                'function' => [
                    'name' => self::SearchCustomers,
                    'description' => 'Find a studio customer by name or phone fragment before investigating account-specific bookings or class passes.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'query' => [
                                'type' => 'string',
                                'description' => 'Customer name or phone fragment from the owner request.',
                            ],
                            'limit' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'maximum' => 10,
                                'default' => 5,
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::InvestigateCustomerBookingLedger,
                    'description' => 'Read a selected customer booking and class-pass timeline, corrections, counters, and deterministic inconsistency findings. Never changes data.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'customer_id' => [
                                'type' => 'integer',
                                'minimum' => 1,
                                'description' => 'Customer ID returned by search_customers.',
                            ],
                            'from_date' => [
                                'type' => 'string',
                                'format' => 'date',
                                'description' => 'Optional first date in YYYY-MM-DD in the studio timezone.',
                            ],
                            'to_date' => [
                                'type' => 'string',
                                'format' => 'date',
                                'description' => 'Optional last date in YYYY-MM-DD in the studio timezone.',
                            ],
                        ],
                        'required' => ['customer_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => self::GetBusinessLogicReference,
                    'description' => 'Read one curated Ladna booking or class-pass business-rule reference when the ledger needs a domain-logic explanation.',
                    'parameters' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'key' => [
                                'type' => 'string',
                                'enum' => $this->businessLogicReference->keys(),
                            ],
                        ],
                        'required' => ['key'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): mixed|null  $progress
     * @return array<string, mixed>
     */
    public function execute(
        Account $account,
        ?User $actorUser,
        string $toolName,
        array $arguments,
        ?AiConversation $conversation = null,
        ?AiConversationMessage $currentMessage = null,
        ?callable $progress = null,
    ): array {
        $startedAt = now();
        $requiredAbility = $this->requiredAbility($toolName);
        $validated = null;

        try {
            if (! $this->isKnownTool($toolName)) {
                throw new InvalidArgumentException('Unknown AI tool.');
            }

            if (! $this->availableForTool($account, $actorUser, $toolName)) {
                throw new AuthorizationException(__('app.api_token_forbidden'));
            }

            $validated = $this->validatedArguments($toolName, $arguments);
            $payload = match ($toolName) {
                self::SearchOwnerHelp => $this->searchOwnerHelp($validated, $progress),
                self::GetOwnerHelpPage => $this->getOwnerHelpPage($validated, $progress),
                self::SearchCustomers => $this->searchCustomers($account, $validated, $progress),
                self::InvestigateCustomerBookingLedger => $this->investigateBookingLedger($account, $validated, $progress),
                self::GetBusinessLogicReference => $this->businessLogic($validated, $progress),
                default => throw new InvalidArgumentException('Unknown AI investigation tool.'),
            };

            $this->recordInvocation(
                $account,
                $conversation,
                $currentMessage,
                $toolName,
                $requiredAbility,
                McpToolInvocationStatus::Succeeded,
                $validated,
                $payload,
                null,
                $startedAt,
            );

            return $payload;
        } catch (Throwable $throwable) {
            $status = $throwable instanceof AuthorizationException
                ? McpToolInvocationStatus::Denied
                : McpToolInvocationStatus::Failed;
            $errorPayload = [
                'status' => 'error',
                'error_code' => match (true) {
                    $throwable instanceof AuthorizationException => 'permission_denied',
                    $throwable instanceof ValidationException, $throwable instanceof InvalidArgumentException => 'invalid_arguments',
                    default => 'tool_failed',
                },
                'message' => match (true) {
                    $throwable instanceof AuthorizationException => __('app.api_token_forbidden'),
                    $throwable instanceof ValidationException => collect($throwable->errors())->flatten()->first()
                        ?? 'The tool arguments are invalid.',
                    $throwable instanceof InvalidArgumentException => $throwable->getMessage(),
                    default => 'Ladna could not verify this data.',
                },
            ];

            $this->recordInvocation(
                $account,
                $conversation,
                $currentMessage,
                $toolName,
                $requiredAbility,
                $status,
                $validated ?? $arguments,
                $errorPayload,
                $throwable->getMessage(),
                $startedAt,
            );

            if (! $throwable instanceof AuthorizationException
                && ! $throwable instanceof ValidationException
                && ! $throwable instanceof InvalidArgumentException) {
                report($throwable);
            }

            return $errorPayload;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function validatedArguments(string $toolName, array $arguments): array
    {
        $rules = match ($toolName) {
            self::SearchOwnerHelp => [
                'query' => ['required', 'string', 'min:2', 'max:120'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:5'],
            ],
            self::GetOwnerHelpPage => [
                'slug' => ['required', 'string', 'max:80'],
            ],
            self::SearchCustomers => [
                'query' => ['required', 'string', 'min:2', 'max:120'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
            ],
            self::InvestigateCustomerBookingLedger => [
                'customer_id' => ['required', 'integer', 'min:1'],
                'from_date' => ['nullable', 'date_format:Y-m-d'],
                'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            ],
            self::GetBusinessLogicReference => [
                'key' => ['required', 'string', 'in:'.implode(',', $this->businessLogicReference->keys())],
            ],
            default => throw new InvalidArgumentException('Unknown AI investigation tool.'),
        };

        return Validator::make($arguments, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): mixed|null  $progress
     * @return array<string, mixed>
     */
    private function searchOwnerHelp(array $arguments, ?callable $progress): array
    {
        $this->progress($progress, 'assistant_status_searching_help');
        $query = (string) $arguments['query'];
        $results = $this->helpIndex->search($query, (int) ($arguments['limit'] ?? 5));

        return [
            'status' => $results === [] ? 'not_found' : 'found',
            'query' => $query,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): mixed|null  $progress
     * @return array<string, mixed>
     */
    private function getOwnerHelpPage(array $arguments, ?callable $progress): array
    {
        $this->progress($progress, 'assistant_status_reading_help');
        $slug = (string) $arguments['slug'];
        $page = config("help.pages.{$slug}");

        if (! is_array($page)) {
            return [
                'status' => 'not_found',
                'slug' => $slug,
            ];
        }

        return [
            'status' => 'found',
            'slug' => $slug,
            'title' => $page['title'] ?? $slug,
            'summary' => $page['summary'] ?? null,
            'sections' => $page['sections'] ?? [],
            'related' => $page['related'] ?? [],
            'updated_at' => config('help.updated_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): mixed|null  $progress
     * @return array<string, mixed>
     */
    private function searchCustomers(Account $account, array $arguments, ?callable $progress): array
    {
        $this->progress($progress, 'assistant_status_searching_customer');

        return $this->customerSearch->search(
            $account,
            (string) $arguments['query'],
            (int) ($arguments['limit'] ?? 5),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): mixed|null  $progress
     * @return array<string, mixed>
     */
    private function investigateBookingLedger(Account $account, array $arguments, ?callable $progress): array
    {
        $this->progress($progress, 'assistant_status_checking_bookings');
        $payload = $this->bookingLedgerInvestigation->investigate(
            $account,
            (int) $arguments['customer_id'],
            isset($arguments['from_date']) ? (string) $arguments['from_date'] : null,
            isset($arguments['to_date']) ? (string) $arguments['to_date'] : null,
        );
        $this->progress($progress, 'assistant_status_checking_class_passes');

        return $this->ledgerEvidencePresenter->present($payload);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  callable(string): mixed|null  $progress
     * @return array<string, mixed>
     */
    private function businessLogic(array $arguments, ?callable $progress): array
    {
        $this->progress($progress, 'assistant_status_checking_business_rules');
        $key = (string) $arguments['key'];

        return [
            'status' => 'found',
            'key' => $key,
            'reference' => $this->businessLogicReference->find($key),
            'available_keys' => $this->businessLogicReference->keys(),
        ];
    }

    private function requiredAbility(string $toolName): ?AccountApiTokenAbility
    {
        return match ($toolName) {
            self::SearchOwnerHelp, self::GetOwnerHelpPage => AccountApiTokenAbility::McpRead,
            self::SearchCustomers => AccountApiTokenAbility::McpCustomersRead,
            self::InvestigateCustomerBookingLedger => AccountApiTokenAbility::McpClassPassesRead,
            self::GetBusinessLogicReference => AccountApiTokenAbility::McpLogicRead,
            default => null,
        };
    }

    private function availableForTool(Account $account, ?User $actorUser, string $toolName): bool
    {
        return match ($toolName) {
            self::SearchOwnerHelp, self::GetOwnerHelpPage => $this->helpAvailableFor($account, $actorUser),
            self::SearchCustomers, self::InvestigateCustomerBookingLedger, self::GetBusinessLogicReference => $this->investigationAvailableFor($account, $actorUser),
            default => false,
        };
    }

    private function isKnownTool(string $toolName): bool
    {
        return in_array($toolName, [
            self::SearchOwnerHelp,
            self::GetOwnerHelpPage,
            self::SearchCustomers,
            self::InvestigateCustomerBookingLedger,
            self::GetBusinessLogicReference,
        ], true);
    }

    /**
     * @param  callable(string): mixed|null  $progress
     */
    private function progress(?callable $progress, string $statusKey): void
    {
        if ($progress) {
            $progress($statusKey);
        }
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $output
     */
    private function recordInvocation(
        Account $account,
        ?AiConversation $conversation,
        ?AiConversationMessage $currentMessage,
        string $toolName,
        ?AccountApiTokenAbility $requiredAbility,
        McpToolInvocationStatus $status,
        ?array $input,
        ?array $output,
        ?string $errorMessage,
        mixed $startedAt,
    ): void {
        McpToolInvocation::create([
            'account_id' => $account->id,
            'account_api_token_id' => null,
            'ai_conversation_id' => $conversation?->id,
            'ai_conversation_message_id' => $currentMessage?->id,
            'tool_name' => $toolName,
            'required_ability' => $requiredAbility?->value,
            'status' => $status->value,
            'input' => $input,
            'output' => $output,
            'error_message' => $errorMessage,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
