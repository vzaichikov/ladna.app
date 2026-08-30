using Ladna.BattleMeter.Models;
using Ladna.BattleMeter.Services;

namespace Ladna.BattleMeter.Tests;

[TestClass]
public sealed class SecureSettingsStoreTests
{
    [TestMethod]
    public void TokenIsProtectedWithCurrentUserDpapiAndRoundTrips()
    {
        if (!OperatingSystem.IsWindows())
        {
            Assert.Inconclusive("Windows DPAPI is available only on Windows.");
        }

        var directory = Path.Combine(Path.GetTempPath(), "Ladna.BattleMeter.Tests", Guid.NewGuid().ToString("N"));
        var path = Path.Combine(directory, "settings.json");

        try
        {
            var store = new SecureSettingsStore(path);
            store.Save(new AppSettings { ServerUrl = "https://ladna.example" }, "never-store-this-token-in-plaintext");

            var rawSettings = File.ReadAllText(path);
            var loaded = store.Load();

            Assert.IsFalse(rawSettings.Contains("never-store-this-token-in-plaintext", StringComparison.Ordinal));
            Assert.AreEqual("never-store-this-token-in-plaintext", loaded.Token);
        }
        finally
        {
            if (Directory.Exists(directory))
            {
                Directory.Delete(directory, true);
            }
        }
    }
}
