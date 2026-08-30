using System.Threading;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using System.Windows.Threading;
using Ladna.BattleMeter.Core;
using Ladna.BattleMeter.Models;

namespace Ladna.BattleMeter.Tests;

[TestClass]
public sealed class AudienceWindowTests
{
    private static Dispatcher? _dispatcher;
    private static Thread? _uiThread;

    [ClassInitialize]
    public static void InitializeWpf(TestContext context)
    {
        using var ready = new ManualResetEventSlim();
        _uiThread = new Thread(() =>
        {
            var application = new App();
            application.InitializeComponent();
            _dispatcher = Dispatcher.CurrentDispatcher;
            ready.Set();
            Dispatcher.Run();
        });
        _uiThread.SetApartmentState(ApartmentState.STA);
        _uiThread.Start();
        ready.Wait();
    }

    [ClassCleanup]
    public static void CleanupWpf()
    {
        _dispatcher?.InvokeShutdown();
        _uiThread?.Join();
    }

    [TestMethod]
    public void RecordingShowsActiveWaveAndShoutingMascotOnOppositeSide()
    {
        RunOnSta(() =>
        {
            var window = AudienceWindow();
            window.ShowRecording(PerformerSide.A);

            Assert.AreEqual(Visibility.Visible, Element<Grid>(window, "WaveA").Visibility);
            Assert.AreEqual(Visibility.Collapsed, Element<Grid>(window, "WaveB").Visibility);
            Assert.AreEqual(Visibility.Collapsed, Element<Border>(window, "MascotCardA").Visibility);
            Assert.AreEqual(Visibility.Visible, Element<Border>(window, "MascotCardB").Visibility);
            Assert.AreEqual(Visibility.Visible, Element<Border>(window, "ActionBanner").Visibility);

            window.ShowRecording(PerformerSide.B);

            Assert.AreEqual(Visibility.Collapsed, Element<Grid>(window, "WaveA").Visibility);
            Assert.AreEqual(Visibility.Visible, Element<Grid>(window, "WaveB").Visibility);
            Assert.AreEqual(Visibility.Visible, Element<Border>(window, "MascotCardA").Visibility);
            Assert.AreEqual(Visibility.Collapsed, Element<Border>(window, "MascotCardB").Visibility);
        });
    }

    [TestMethod]
    public void CountdownUsesCircularThreeTwoOnePresentation()
    {
        RunOnSta(() =>
        {
            var window = AudienceWindow();
            var countdownPanel = Element<Grid>(window, "CountdownPanel");
            var countdownNumber = Element<TextBlock>(window, "CountdownNumber");

            window.ShowCountdown(PerformerSide.A, 3);
            Assert.AreEqual(Visibility.Visible, countdownPanel.Visibility);
            Assert.AreEqual("3", countdownNumber.Text);

            window.ShowCountdown(PerformerSide.A, 2);
            Assert.AreEqual("2", countdownNumber.Text);

            window.ShowCountdown(PerformerSide.A, 1);
            Assert.AreEqual("1", countdownNumber.Text);
        });
    }

    [TestMethod]
    public void AudiencePanelsUseVioletWhiteAndWhiteVioletContrast()
    {
        RunOnSta(() =>
        {
            var window = AudienceWindow();
            var panelA = Element<Border>(window, "PanelA");
            var panelB = Element<Border>(window, "PanelB");
            var nameA = Element<TextBlock>(window, "NameA");
            var nameB = Element<TextBlock>(window, "NameB");

            Assert.AreEqual(Color.FromRgb(0x3B, 0x22, 0x3F), ((SolidColorBrush)panelA.Background).Color);
            Assert.AreEqual(Colors.White, ((SolidColorBrush)panelB.Background).Color);
            Assert.AreEqual(Colors.White, ((SolidColorBrush)nameA.Foreground).Color);
            Assert.AreEqual(Color.FromRgb(0x3B, 0x22, 0x3F), ((SolidColorBrush)nameB.Foreground).Color);
        });
    }

    [TestMethod]
    public void LiveLevelIsLargeAndUsesMaximumPanelContrast()
    {
        RunOnSta(() =>
        {
            var window = AudienceWindow();
            var coreA = Element<Border>(window, "WaveCoreA");
            var coreB = Element<Border>(window, "WaveCoreB");
            var levelA = Element<TextBlock>(window, "LevelA");
            var levelB = Element<TextBlock>(window, "LevelB");

            Assert.IsTrue(coreA.Width >= 300);
            Assert.IsTrue(coreB.Width >= 300);
            Assert.IsTrue(levelA.FontSize >= 50);
            Assert.IsTrue(levelB.FontSize >= 50);
            Assert.AreEqual(Colors.White, ((SolidColorBrush)coreA.Background).Color);
            Assert.AreEqual(Color.FromRgb(0x3B, 0x22, 0x3F), ((SolidColorBrush)levelA.Foreground).Color);
            Assert.AreEqual(Color.FromRgb(0x3B, 0x22, 0x3F), ((SolidColorBrush)coreB.Background).Color);
            Assert.AreEqual(Colors.White, ((SolidColorBrush)levelB.Foreground).Color);
        });
    }

    [TestMethod]
    public void AcceptedLevelsPersistUntilTheirCaptureIsCleared()
    {
        RunOnSta(() =>
        {
            var window = AudienceWindow();
            var acceptedCardA = Element<Border>(window, "AcceptedCardA");
            var acceptedCardB = Element<Border>(window, "AcceptedCardB");
            var acceptedLevelA = Element<TextBlock>(window, "AcceptedLevelA");
            var acceptedLevelB = Element<TextBlock>(window, "AcceptedLevelB");

            window.ShowAcceptedCapture(PerformerSide.A, Statistics(-12.3));

            Assert.AreEqual(Visibility.Visible, acceptedCardA.Visibility);
            Assert.AreEqual(Visibility.Collapsed, acceptedCardB.Visibility);
            Assert.AreEqual("-12.3 dBFS", acceptedLevelA.Text);

            window.ShowAcceptedCapture(PerformerSide.B, Statistics(-15.7));

            Assert.AreEqual(Visibility.Visible, acceptedCardA.Visibility);
            Assert.AreEqual(Visibility.Visible, acceptedCardB.Visibility);
            Assert.AreEqual("-15.7 dBFS", acceptedLevelB.Text);

            window.ShowRecording(PerformerSide.A);
            Assert.AreEqual(Visibility.Collapsed, acceptedCardA.Visibility);
            Assert.AreEqual(Visibility.Collapsed, acceptedCardB.Visibility);

            window.RestoreAcceptedCaptures();
            Assert.AreEqual(Visibility.Visible, acceptedCardA.Visibility);
            Assert.AreEqual(Visibility.Visible, acceptedCardB.Visibility);

            window.ClearAcceptedCapture(PerformerSide.A);
            Assert.AreEqual(Visibility.Collapsed, acceptedCardA.Visibility);
            Assert.AreEqual(Visibility.Visible, acceptedCardB.Visibility);

            var reopenedWindow = AudienceWindow();
            reopenedWindow.SetAcceptedCaptures(Statistics(-12.3), Statistics(-15.7));
            Assert.AreEqual(Visibility.Visible, Element<Border>(reopenedWindow, "AcceptedCardA").Visibility);
            Assert.AreEqual(Visibility.Visible, Element<Border>(reopenedWindow, "AcceptedCardB").Visibility);
        });
    }

    private static AudienceWindow AudienceWindow()
    {
        var window = new AudienceWindow();
        window.SetLocale("en");
        window.SetMatch(new BattleMatch
        {
            Id = 42,
            PerformerA = new BattlePerformer { Id = 1, Name = "Performer A" },
            PerformerB = new BattlePerformer { Id = 2, Name = "Performer B" },
        });

        return window;
    }

    private static CaptureStatistics Statistics(double relativeDbfs)
    {
        return new CaptureStatistics(
            240_000,
            0.1,
            Math.Sqrt(0.1),
            0.5,
            relativeDbfs,
            -2,
            0,
            TimeSpan.FromSeconds(5));
    }

    private static T Element<T>(AudienceWindow window, string name) where T : FrameworkElement
    {
        return window.FindName(name) as T
            ?? throw new AssertFailedException($"Audience element '{name}' was not found.");
    }

    private static void RunOnSta(Action action)
    {
        (_dispatcher ?? throw new AssertFailedException("The WPF dispatcher was not initialized.")).Invoke(action);
    }
}
