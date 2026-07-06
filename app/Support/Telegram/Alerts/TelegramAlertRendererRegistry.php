<?php

namespace App\Support\Telegram\Alerts;

use App\Enums\TelegramAlertType;
use App\Models\Account;
use InvalidArgumentException;

class TelegramAlertRendererRegistry
{
    public function __construct(
        private readonly TrainerAssignmentTelegramAlertRenderer $trainerAssignmentRenderer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(TelegramAlertType $type, Account $account, array $payload): string
    {
        return $this->renderer($type)->render($account, $payload);
    }

    private function renderer(TelegramAlertType $type): TelegramAlertRenderer
    {
        return match ($type) {
            TelegramAlertType::TrainerAssignment => $this->trainerAssignmentRenderer,
            default => throw new InvalidArgumentException('Unsupported Telegram alert type: '.$type->value),
        };
    }
}
