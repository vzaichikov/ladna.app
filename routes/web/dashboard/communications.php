<?php

use App\Http\Controllers\AccountActivityLogController;
use App\Http\Controllers\AccountApiTokenController;
use App\Http\Controllers\AccountAssistantController;
use App\Http\Controllers\AccountCustomerNotificationController;
use App\Http\Controllers\AccountCustomerTelegramBotController;
use App\Http\Controllers\AccountCustomerTelegramWebhookController;
use App\Http\Controllers\AccountSmsAccountController;
use App\Http\Controllers\AccountSmsAutoTopUpController;
use App\Http\Controllers\AccountSmsTopUpController;
use App\Http\Controllers\AccountTelegramAlertController;
use App\Http\Controllers\AccountTelegramConnectionController;
use App\Http\Controllers\AiConversationMessageAttachmentController;
use App\Http\Controllers\CameraController;
use App\Http\Controllers\CustomerNotificationSettingsController;
use App\Http\Controllers\TrainerNotificationSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('accounts/{account}/cameras', [CameraController::class, 'index'])
    ->name('accounts.cameras.index');
Route::get('accounts/{account}/activity-logs', [AccountActivityLogController::class, 'index'])
    ->name('accounts.activity-logs.index');
Route::get('accounts/{account}/customer-notification-logs', [AccountCustomerNotificationController::class, 'index'])
    ->name('accounts.customer-notification-logs.index');
Route::get('accounts/{account}/sms-account', [AccountSmsAccountController::class, 'show'])
    ->name('accounts.sms-account.show');
Route::post('accounts/{account}/sms-account/top-ups', [AccountSmsTopUpController::class, 'store'])
    ->name('accounts.sms-account.top-ups.store');
Route::put('accounts/{account}/sms-account/auto-top-up', [AccountSmsAutoTopUpController::class, 'update'])
    ->name('accounts.sms-account.auto-top-up.update');
Route::get('accounts/{account}/trainer-telegram-alert-logs', [AccountTelegramAlertController::class, 'index'])
    ->name('accounts.trainer-telegram-alert-logs.index');
Route::post('accounts/{account}/api-tokens', [AccountApiTokenController::class, 'store'])
    ->name('accounts.api-tokens.store');
Route::post('accounts/{account}/api-tokens/{accountApiToken}/regenerate', [AccountApiTokenController::class, 'regenerate'])
    ->name('accounts.api-tokens.regenerate');
Route::delete('accounts/{account}/api-tokens/{accountApiToken}', [AccountApiTokenController::class, 'destroy'])
    ->name('accounts.api-tokens.destroy');
Route::put('accounts/{account}/customer-notification-settings', [CustomerNotificationSettingsController::class, 'update'])
    ->name('accounts.customer-notification-settings.update');
Route::put('accounts/{account}/customer-telegram-bot', [AccountCustomerTelegramBotController::class, 'update'])
    ->name('accounts.customer-telegram-bot.update');
Route::put('accounts/{account}/customer-telegram-bot/placements', [AccountCustomerTelegramBotController::class, 'updatePlacements'])
    ->name('accounts.customer-telegram-bot.placements.update');
Route::get('accounts/{account}/customer-telegram-bot/webhook-status', [AccountCustomerTelegramWebhookController::class, 'show'])
    ->name('accounts.customer-telegram-bot.webhook-status');
Route::post('accounts/{account}/customer-telegram-bot/register-webhook', [AccountCustomerTelegramWebhookController::class, 'store'])
    ->name('accounts.customer-telegram-bot.register-webhook');
Route::delete('accounts/{account}/customer-telegram-bot/webhook', [AccountCustomerTelegramWebhookController::class, 'destroy'])
    ->name('accounts.customer-telegram-bot.delete-webhook');
Route::post('accounts/{account}/customer-telegram-bot/reconnect', [AccountCustomerTelegramBotController::class, 'reconnect'])
    ->name('accounts.customer-telegram-bot.reconnect');
Route::post('accounts/{account}/customer-telegram-bot/check', [AccountCustomerTelegramBotController::class, 'check'])
    ->name('accounts.customer-telegram-bot.check');
Route::patch('accounts/{account}/customer-telegram-bot/disable', [AccountCustomerTelegramBotController::class, 'disable'])
    ->name('accounts.customer-telegram-bot.disable');
Route::delete('accounts/{account}/customer-telegram-bot', [AccountCustomerTelegramBotController::class, 'destroy'])
    ->name('accounts.customer-telegram-bot.destroy');
Route::get('accounts/{account}/telegram-connections', [AccountTelegramConnectionController::class, 'index'])
    ->name('accounts.telegram-connections.index');
Route::post('accounts/{account}/telegram-connections/{telegramAuthorization}/reset', [AccountTelegramConnectionController::class, 'reset'])
    ->name('accounts.telegram-connections.reset');
Route::delete('accounts/{account}/telegram-connections/{telegramAuthorization}', [AccountTelegramConnectionController::class, 'revoke'])
    ->name('accounts.telegram-connections.revoke');
Route::put('accounts/{account}/trainer-notification-settings', [TrainerNotificationSettingsController::class, 'update'])
    ->name('accounts.trainer-notification-settings.update');
Route::get('accounts/{account}/assistant', [AccountAssistantController::class, 'show'])
    ->name('accounts.assistant.show');
Route::get('accounts/{account}/assistant/attachments/{attachment}', AiConversationMessageAttachmentController::class)
    ->name('accounts.assistant.attachments.show');
Route::post('accounts/{account}/assistant/messages', [AccountAssistantController::class, 'store'])
    ->name('accounts.assistant.messages.store');
Route::delete('accounts/{account}/assistant', [AccountAssistantController::class, 'destroy'])
    ->name('accounts.assistant.destroy');
Route::post('accounts/{account}/assistant/actions/{action}/confirm', [AccountAssistantController::class, 'confirm'])
    ->name('accounts.assistant.actions.confirm');
Route::post('accounts/{account}/assistant/actions/{action}/cancel', [AccountAssistantController::class, 'cancel'])
    ->name('accounts.assistant.actions.cancel');
