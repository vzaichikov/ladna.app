namespace Ladna.BattleMeter.Models;

public sealed class AppSettings
{
    public string ServerUrl { get; set; } = "https://ladna.app";

    public string? MicrophoneId { get; set; }

    public string? AudienceDisplayId { get; set; }

    public string Locale { get; set; } = "en";
}

internal sealed class StoredSettings
{
    public string ServerUrl { get; set; } = "https://ladna.app";

    public string? MicrophoneId { get; set; }

    public string? AudienceDisplayId { get; set; }

    public string Locale { get; set; } = "en";

    public string? ProtectedToken { get; set; }
}
