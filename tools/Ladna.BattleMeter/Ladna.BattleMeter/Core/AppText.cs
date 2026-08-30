using System.Globalization;

namespace Ladna.BattleMeter.Core;

public static class AppText
{
    private static readonly IReadOnlyDictionary<string, IReadOnlyDictionary<string, string>> Values =
        new Dictionary<string, IReadOnlyDictionary<string, string>>(StringComparer.OrdinalIgnoreCase)
        {
            ["en"] = new Dictionary<string, string>
            {
                ["AppTitle"] = "Ladna Battle Applause Meter",
                ["Connect"] = "Connect and load matches",
                ["Save"] = "Save settings",
                ["RefreshDevices"] = "Refresh devices",
                ["TestMicrophone"] = "Test microphone",
                ["ShowAudience"] = "Show audience display",
                ["CloseAudience"] = "Close audience display",
                ["CaptureBaseline"] = "Capture 2 s baseline",
                ["CaptureA"] = "Capture performer A",
                ["CaptureB"] = "Capture performer B",
                ["RetakeA"] = "Retake A",
                ["RetakeB"] = "Retake B",
                ["Submit"] = "Submit audience vote",
                ["Retry"] = "Retry submission",
                ["StopWaiting"] = "Stop waiting",
                ["Ready"] = "Ready",
                ["Countdown"] = "GET READY",
                ["Recording"] = "MAKE SOME NOISE!",
                ["WaitingJudges"] = "Waiting for all four jury votes…",
                ["WaitingJury"] = "Jury decision required",
                ["Winner"] = "WINNER",
                ["Ambient"] = "AMBIENT BASELINE",
                ["Jury"] = "Jury",
                ["Audience"] = "Audience",
                ["Combined"] = "Combined",
                ["ConfirmRetake"] = "Replace the existing capture for this performer?",
                ["LocalCertificateWarning"] = "Certificate validation is disabled for this local server during the current app session. Production servers are never exempted.",
                ["LocalCertificateConfirm"] = "Windows may not trust this local HTTPS development certificate. Allow this app to accept it for this server during the current session? Continue only if you control and trust the local server.",
            },
            ["uk"] = new Dictionary<string, string>
            {
                ["AppTitle"] = "Ladna — шумомір батлу",
                ["Connect"] = "Підключитися й завантажити батли",
                ["Save"] = "Зберегти налаштування",
                ["RefreshDevices"] = "Оновити пристрої",
                ["TestMicrophone"] = "Перевірити мікрофон",
                ["ShowAudience"] = "Показати екран глядачів",
                ["CloseAudience"] = "Закрити екран глядачів",
                ["CaptureBaseline"] = "Записати фон 2 с",
                ["CaptureA"] = "Записати учасника A",
                ["CaptureB"] = "Записати учасника B",
                ["RetakeA"] = "Перезаписати A",
                ["RetakeB"] = "Перезаписати B",
                ["Submit"] = "Надіслати голос глядачів",
                ["Retry"] = "Повторити надсилання",
                ["StopWaiting"] = "Зупинити очікування",
                ["Ready"] = "Готово",
                ["Countdown"] = "ПРИГОТУЙТЕСЯ",
                ["Recording"] = "ГОЛОСНІШЕ!",
                ["WaitingJudges"] = "Очікуємо всі чотири голоси журі…",
                ["WaitingJury"] = "Потрібне рішення журі",
                ["Winner"] = "ПЕРЕМОЖЕЦЬ",
                ["Ambient"] = "ФОНОВИЙ РІВЕНЬ",
                ["Jury"] = "Журі",
                ["Audience"] = "Глядачі",
                ["Combined"] = "Разом",
                ["ConfirmRetake"] = "Замінити наявний запис цього учасника?",
                ["LocalCertificateWarning"] = "Перевірку сертифіката вимкнено для цього локального сервера до закриття програми. Для робочих серверів виняток ніколи не застосовується.",
                ["LocalCertificateConfirm"] = "Windows може не довіряти цьому локальному HTTPS-сертифікату для розробки. Дозволити програмі приймати його для цього сервера до завершення поточного сеансу? Продовжуйте лише якщо ви контролюєте локальний сервер і довіряєте йому.",
            },
        };

    public static string NormalizeLocale(string? locale)
    {
        if (string.Equals(locale, "uk", StringComparison.OrdinalIgnoreCase) ||
            string.Equals(locale, "uk-UA", StringComparison.OrdinalIgnoreCase))
        {
            return "uk";
        }

        return "en";
    }

    public static string SystemLocale => NormalizeLocale(CultureInfo.CurrentUICulture.Name);

    public static string Get(string locale, string key)
    {
        var normalized = NormalizeLocale(locale);
        return Values[normalized].TryGetValue(key, out var value) ? value : key;
    }
}
