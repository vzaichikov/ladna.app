# Current notification framework map

## Shared business hooks

- `App\Support\CustomerNotifications\ClassBookingNotificationCoordinator` coordinates notification side effects after booking and scheduled-class actions commit.
- `App\Actions\CancelClassBooking` is the shared status-cancellation path for studio web, customer web, mobile, and assistant flows.
- `App\Actions\CancelScheduledClassForStudio` is the shared studio whole-class cancellation path.
- `App\Actions\RestoreScheduledClassCancellation` is the reversal path and must suppress pending cancellation notices.
- `ClassBookingController::destroy` is the exceptional hard-delete path and must explicitly call the coordinator.

## Trainer Telegram outbox

- Type enum: `App\Enums\TelegramAlertType`
- Persistent row: `App\Models\TelegramAlert`
- Producer/renderer registry: `App\Support\Telegram\Alerts\TelegramAlertProducer` and `TelegramAlertRendererRegistry`
- Delivery recheck and external request: `App\Support\Telegram\Alerts\TelegramAlertSender`
- Master switch: `accounts.enable_telegram_alerts`
- Scenario switches: `trainer_notification_settings`
- Trainer delivery requires authorization in the platform owner/general Ladna bot.

## Customer SMS outbox

- Type enum: `App\Enums\CustomerNotificationType`
- Persistent row: `App\Models\CustomerNotification`
- Producer: `App\Support\CustomerNotifications\CustomerNotificationProducer`
- Text: `CustomerNotificationTextRenderer`
- Delivery recheck and external request: `CustomerNotificationSender`
- Platform capability: `accounts.enable_customer_notifications`
- Studio master/scenarios: `customer_notification_settings`
- Provider resolution uses `CustomerAuthAvailability` and `SmsGatewayResolver`.

## Studio settings surface

- Route/page: `dashboard.accounts.notification-settings.edit`
- Trainer form: `resources/views/accounts/trainer-notification-settings.blade.php`
- Customer form: `resources/views/accounts/customer-notification-settings.blade.php`
- Controllers/requests: `TrainerNotificationSettingsController`, `CustomerNotificationSettingsController`, and their form requests.
- Both forms use a master card followed by `notification_scenarios` cards with plain-language delivery/channel legends.

## Test anchors

- `tests/Feature/TelegramAlertTest.php`
- `tests/Feature/CustomerNotificationQueueTest.php`
- `tests/Feature/TrainerNotificationSettingsTest.php`
- `tests/Feature/CustomerNotificationSettingsTest.php`
- Add scenario-specific feature tests when behavior spans booking/class actions and sender delivery.
