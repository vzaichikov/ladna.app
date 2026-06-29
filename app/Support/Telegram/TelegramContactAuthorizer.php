<?php

namespace App\Support\Telegram;

use App\Enums\StudioPermission;
use App\Enums\TelegramBotProfile;
use App\Enums\TelegramChatAuthorizationStatus;
use App\Models\AccountMembership;
use App\Models\TelegramAuthorizationSelection;
use App\Models\TelegramAuthorizationSelectionCandidate;
use App\Models\TelegramBotInstallation;
use App\Models\TelegramChatAuthorization;
use App\Models\Trainer;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Collection;

class TelegramContactAuthorizer
{
    public function __construct(private readonly PhoneNumberNormalizer $phoneNumberNormalizer) {}

    /**
     * @param  array<string, mixed>  $message
     * @return array{status: string, authorization?: TelegramChatAuthorization, selection?: TelegramAuthorizationSelection}
     */
    public function authorize(TelegramBotInstallation $installation, array $message): array
    {
        $contact = data_get($message, 'contact');
        $fromUserId = data_get($message, 'from.id');
        $contactUserId = data_get($contact, 'user_id');

        if (! is_array($contact) || ! $fromUserId || ! $contactUserId || (string) $fromUserId !== (string) $contactUserId) {
            return ['status' => 'failed'];
        }

        $phone = $this->phoneNumberNormalizer->normalize(
            (string) data_get($contact, 'phone_number'),
            $installation->account?->country_code ?? 'UA',
        );

        if (! $phone || $installation->profile !== TelegramBotProfile::Owner) {
            return ['status' => 'failed'];
        }

        $candidates = $this->ownerCandidates($phone);

        if ($candidates->isEmpty()) {
            return ['status' => 'not_found'];
        }

        if ($candidates->count() === 1) {
            return [
                'status' => 'authorized',
                'authorization' => $this->createAuthorization($installation, $message, $phone, $candidates->first()),
            ];
        }

        return [
            'status' => 'selection_required',
            'selection' => $this->createSelection($installation, $message, $phone, $candidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $callbackQuery
     */
    public function authorizeSelection(TelegramBotInstallation $installation, array $callbackQuery): ?TelegramChatAuthorization
    {
        $data = (string) data_get($callbackQuery, 'data', '');

        if (preg_match('/^tg_select:(\d+)$/', $data, $matches) !== 1) {
            return null;
        }

        $candidate = TelegramAuthorizationSelectionCandidate::query()
            ->with(['selection', 'account', 'user', 'trainer'])
            ->whereKey((int) $matches[1])
            ->whereHas('selection', function ($query) use ($installation, $callbackQuery): void {
                $query->where('telegram_bot_installation_id', $installation->id)
                    ->where('telegram_chat_id', (string) data_get($callbackQuery, 'message.chat.id'))
                    ->where('telegram_user_id', (string) data_get($callbackQuery, 'from.id'))
                    ->where('status', TelegramAuthorizationSelection::StatusPending)
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
            })
            ->first();

        if (! $candidate || ! $candidate->selection) {
            return null;
        }

        $authorization = TelegramChatAuthorization::updateOrCreate(
            [
                'telegram_bot_installation_id' => $installation->id,
                'telegram_chat_id' => (string) data_get($callbackQuery, 'message.chat.id'),
            ],
            [
                'account_id' => $candidate->account_id,
                'user_id' => $candidate->user_id,
                'trainer_id' => $candidate->trainer_id,
                'profile' => TelegramBotProfile::Owner->value,
                'telegram_user_id' => (string) data_get($callbackQuery, 'from.id'),
                'telegram_username' => data_get($callbackQuery, 'from.username'),
                'phone' => $candidate->selection->phone,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
                'authorized_at' => now(),
                'revoked_at' => null,
            ],
        );

        $candidate->selection->update(['status' => TelegramAuthorizationSelection::StatusAuthorized]);

        return $authorization;
    }

    /**
     * @return Collection<int, array{account_id: int, user_id: int, trainer_id: int|null, label: string}>
     */
    private function ownerCandidates(string $phone): Collection
    {
        $memberships = AccountMembership::query()
            ->whereHas('user', fn ($query) => $query->where('phone', $phone))
            ->with(['account', 'user'])
            ->get()
            ->filter(fn (AccountMembership $membership): bool => $membership->allows(StudioPermission::InteractWithTelegramBot))
            ->map(function (AccountMembership $membership) use ($phone): array {
                $trainer = Trainer::query()
                    ->where('account_id', $membership->account_id)
                    ->where('is_active', true)
                    ->where(function ($query) use ($membership, $phone): void {
                        $query
                            ->where('user_id', $membership->user_id)
                            ->orWhere('phone', $phone);
                    })
                    ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$membership->user_id])
                    ->first();

                return [
                    'account_id' => $membership->account_id,
                    'user_id' => $membership->user_id,
                    'trainer_id' => $trainer?->id,
                    'label' => $membership->account?->name ?? ('#'.$membership->account_id),
                    'priority' => $trainer ? 0 : 1,
                ];
            });

        $trainerMemberships = Trainer::query()
            ->where('phone', $phone)
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->with(['account', 'user'])
            ->get()
            ->map(function (Trainer $trainer): ?array {
                $membership = AccountMembership::query()
                    ->where('account_id', $trainer->account_id)
                    ->where('user_id', $trainer->user_id)
                    ->with('account')
                    ->first();

                if (! $membership || ! $membership->allows(StudioPermission::InteractWithTelegramBot)) {
                    return null;
                }

                return [
                    'account_id' => $trainer->account_id,
                    'user_id' => (int) $trainer->user_id,
                    'trainer_id' => $trainer->id,
                    'label' => $trainer->account?->name ?? ('#'.$trainer->account_id),
                    'priority' => 2,
                ];
            })
            ->filter();

        return $memberships
            ->merge($trainerMemberships)
            ->sortBy([
                ['account_id', 'asc'],
                ['priority', 'asc'],
            ])
            ->unique('account_id')
            ->map(fn (array $candidate): array => [
                'account_id' => $candidate['account_id'],
                'user_id' => $candidate['user_id'],
                'trainer_id' => $candidate['trainer_id'],
                'label' => $candidate['label'],
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array{account_id: int, user_id: int, trainer_id: int|null, label: string}  $candidate
     */
    private function createAuthorization(TelegramBotInstallation $installation, array $message, string $phone, array $candidate): TelegramChatAuthorization
    {
        return TelegramChatAuthorization::updateOrCreate(
            [
                'telegram_bot_installation_id' => $installation->id,
                'telegram_chat_id' => (string) data_get($message, 'chat.id'),
            ],
            [
                'account_id' => $candidate['account_id'],
                'user_id' => $candidate['user_id'],
                'trainer_id' => $candidate['trainer_id'],
                'profile' => TelegramBotProfile::Owner->value,
                'telegram_user_id' => (string) data_get($message, 'from.id'),
                'telegram_username' => data_get($message, 'from.username'),
                'phone' => $phone,
                'status' => TelegramChatAuthorizationStatus::Authorized->value,
                'authorized_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  Collection<int, array{account_id: int, user_id: int, trainer_id: int|null, label: string}>  $candidates
     */
    private function createSelection(TelegramBotInstallation $installation, array $message, string $phone, Collection $candidates): TelegramAuthorizationSelection
    {
        $selection = TelegramAuthorizationSelection::create([
            'telegram_bot_installation_id' => $installation->id,
            'profile' => TelegramBotProfile::Owner->value,
            'telegram_chat_id' => (string) data_get($message, 'chat.id'),
            'telegram_user_id' => (string) data_get($message, 'from.id'),
            'telegram_username' => data_get($message, 'from.username'),
            'phone' => $phone,
            'status' => TelegramAuthorizationSelection::StatusPending,
            'expires_at' => now()->addMinutes(10),
        ]);

        $candidates->each(function (array $candidate) use ($selection): void {
            $selection->candidates()->create([
                'account_id' => $candidate['account_id'],
                'user_id' => $candidate['user_id'],
                'trainer_id' => $candidate['trainer_id'],
                'label' => $candidate['label'],
            ]);
        });

        return $selection->load('candidates');
    }
}
