using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;
using Forms = System.Windows.Forms;

namespace Ladna.BattleMeter.Services;

public sealed record AudienceDisplay(string Id, string Name, bool IsPrimary, int X, int Y, int Width, int Height)
{
    public override string ToString() => IsPrimary ? $"{Name} (primary)" : Name;
}

public static class AudienceDisplayService
{
    private const uint SwpShowWindow = 0x0040;
    private static readonly nint HwndTopmost = new(-1);

    public static IReadOnlyList<AudienceDisplay> GetDisplays()
    {
        return Forms.Screen.AllScreens
            .Select((screen, index) => new AudienceDisplay(
                screen.DeviceName,
                $"Display {index + 1} · {screen.Bounds.Width}×{screen.Bounds.Height}",
                screen.Primary,
                screen.Bounds.X,
                screen.Bounds.Y,
                screen.Bounds.Width,
                screen.Bounds.Height))
            .ToArray();
    }

    public static void PlaceFullscreen(Window window, AudienceDisplay display)
    {
        ArgumentNullException.ThrowIfNull(window);
        ArgumentNullException.ThrowIfNull(display);

        window.WindowStyle = WindowStyle.None;
        window.ResizeMode = ResizeMode.NoResize;
        window.ShowInTaskbar = false;
        window.Topmost = true;

        void Place(object? sender, EventArgs args)
        {
            window.SourceInitialized -= Place;
            var handle = new WindowInteropHelper(window).Handle;
            SetWindowPos(
                handle,
                HwndTopmost,
                display.X,
                display.Y,
                display.Width,
                display.Height,
                SwpShowWindow);
        }

        window.SourceInitialized += Place;

        if (!window.IsVisible)
        {
            window.Show();
        }
        else
        {
            Place(null, EventArgs.Empty);
        }
    }

    [DllImport("user32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetWindowPos(
        nint window,
        nint insertAfter,
        int x,
        int y,
        int width,
        int height,
        uint flags);
}
