using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.IO;
using Ladna.BattleMeter.Models;

namespace Ladna.BattleMeter.Services;

public sealed record LoadedSettings(AppSettings Settings, string Token);

public sealed class SecureSettingsStore
{
    private static readonly byte[] Entropy = Encoding.UTF8.GetBytes("Ladna.BattleMeter.v1");
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };
    private readonly string _settingsPath;

    public SecureSettingsStore(string? settingsPath = null)
    {
        _settingsPath = settingsPath ?? Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "Ladna",
            "BattleMeter",
            "settings.json");
    }

    public LoadedSettings Load()
    {
        if (!File.Exists(_settingsPath))
        {
            return new LoadedSettings(new AppSettings(), string.Empty);
        }

        try
        {
            var stored = JsonSerializer.Deserialize<StoredSettings>(File.ReadAllText(_settingsPath), JsonOptions)
                ?? new StoredSettings();
            var token = string.IsNullOrWhiteSpace(stored.ProtectedToken)
                ? string.Empty
                : Unprotect(stored.ProtectedToken);

            return new LoadedSettings(
                new AppSettings
                {
                    ServerUrl = stored.ServerUrl,
                    MicrophoneId = stored.MicrophoneId,
                    AudienceDisplayId = stored.AudienceDisplayId,
                    Locale = stored.Locale,
                },
                token);
        }
        catch (Exception exception) when (exception is CryptographicException or JsonException or IOException or FormatException)
        {
            return new LoadedSettings(new AppSettings(), string.Empty);
        }
    }

    public void Save(AppSettings settings, string token)
    {
        ArgumentNullException.ThrowIfNull(settings);

        var directory = Path.GetDirectoryName(_settingsPath)
            ?? throw new InvalidOperationException("The settings path has no directory.");
        Directory.CreateDirectory(directory);

        var stored = new StoredSettings
        {
            ServerUrl = settings.ServerUrl,
            MicrophoneId = settings.MicrophoneId,
            AudienceDisplayId = settings.AudienceDisplayId,
            Locale = settings.Locale,
            ProtectedToken = string.IsNullOrWhiteSpace(token) ? null : Protect(token.Trim()),
        };

        var temporaryPath = _settingsPath + ".tmp";
        File.WriteAllText(temporaryPath, JsonSerializer.Serialize(stored, JsonOptions));
        File.Move(temporaryPath, _settingsPath, true);
    }

    private static string Protect(string value)
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("DPAPI token protection requires Windows.");
        }

        var protectedBytes = ProtectedData.Protect(
            Encoding.UTF8.GetBytes(value),
            Entropy,
            DataProtectionScope.CurrentUser);

        return Convert.ToBase64String(protectedBytes);
    }

    private static string Unprotect(string value)
    {
        if (!OperatingSystem.IsWindows())
        {
            throw new PlatformNotSupportedException("DPAPI token protection requires Windows.");
        }

        var bytes = ProtectedData.Unprotect(
            Convert.FromBase64String(value),
            Entropy,
            DataProtectionScope.CurrentUser);

        return Encoding.UTF8.GetString(bytes);
    }
}
