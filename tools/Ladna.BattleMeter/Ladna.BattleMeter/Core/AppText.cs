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
