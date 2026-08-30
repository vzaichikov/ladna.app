using System.Globalization;
using System.Windows;
using System.Windows.Media;
using System.Windows.Media.Animation;
using System.Windows.Shapes;
using Ladna.BattleMeter.Core;
using Ladna.BattleMeter.Models;

namespace Ladna.BattleMeter;

public partial class AudienceWindow : Window
{
    private static readonly Duration RippleDuration = new(TimeSpan.FromSeconds(1.45));
    private string _locale = "en";
    private double? _acceptedDbfsA;
    private double? _acceptedDbfsB;

    public AudienceWindow()
    {
        InitializeComponent();
    }

    public void SetLocale(string locale)
    {
        _locale = AppText.NormalizeLocale(locale);
        CloseAudienceButton.ToolTip = AppText.Get(_locale, "CloseAudience");
        CountdownCaption.Text = AppText.Get(_locale, "Countdown");
        UpdateAcceptedCardText();
    }

    public void SetMatch(BattleMatch? match)
    {
        NameA.Text = match?.PerformerA?.Name ?? "A";
        NameB.Text = match?.PerformerB?.Name ?? "B";
        ClearMeasurements();
    }

    public void ClearMeasurements()
    {
        _acceptedDbfsA = null;
        _acceptedDbfsB = null;
        LevelA.Text = "−∞ dBFS";
        LevelB.Text = "−∞ dBFS";
        StatsA.Text = string.Empty;
        StatsB.Text = string.Empty;
        SetNeutralPanels();
        HideMessage();
    }

    public void ShowBaseline()
    {
        _acceptedDbfsA = null;
        _acceptedDbfsB = null;
        SetNeutralPanels();
        ResetStageVisuals();
        StartWave(PerformerSide.A);
        StartWave(PerformerSide.B);
        ShowActionBanner(AppText.Get(_locale, "Ambient"));
    }

    public void ShowCountdown(PerformerSide side, int seconds)
    {
        SetNeutralPanels();
        ResetStageVisuals();
        ActionBanner.Visibility = Visibility.Collapsed;
        CenterMessagePanel.Visibility = Visibility.Collapsed;
        CountdownCaption.Text = AppText.Get(_locale, "Countdown");
        CountdownNumber.Text = seconds.ToString();
        CountdownPanel.Visibility = Visibility.Visible;
        AnimateCountdownTick();
    }

    public void ShowRecording(PerformerSide side)
    {
        SetNeutralPanels();
        ResetStageVisuals();

        if (side == PerformerSide.A)
        {
            StartWave(PerformerSide.A);
            StartMascot(PerformerSide.B);
        }
        else
        {
            StartWave(PerformerSide.B);
            StartMascot(PerformerSide.A);
        }

        ShowActionBanner(AppText.Get(_locale, "Recording"));
    }

    public void ShowAcceptedCapture(PerformerSide side, CaptureStatistics capture)
    {
        if (side == PerformerSide.A)
        {
            _acceptedDbfsA = capture.RelativeDbfs;
        }
        else
        {
            _acceptedDbfsB = capture.RelativeDbfs;
        }

        RestoreAcceptedCaptures();
    }

    public void SetAcceptedCaptures(CaptureStatistics? captureA, CaptureStatistics? captureB)
    {
        _acceptedDbfsA = captureA?.RelativeDbfs;
        _acceptedDbfsB = captureB?.RelativeDbfs;
        RestoreAcceptedCaptures();
    }

    public void ClearAcceptedCapture(PerformerSide side)
    {
        if (side == PerformerSide.A)
        {
            _acceptedDbfsA = null;
        }
        else
        {
            _acceptedDbfsB = null;
        }

        RestoreAcceptedCaptures();
    }

    public void RestoreAcceptedCaptures()
    {
        ActionBanner.Visibility = Visibility.Collapsed;
        CenterMessagePanel.Visibility = Visibility.Collapsed;
        CenterCaption.Text = string.Empty;
        CenterMessage.Text = string.Empty;
        ResetStageVisuals();
        SetNeutralPanels();
        UpdateAcceptedCardText();
        AcceptedCardA.Visibility = _acceptedDbfsA.HasValue ? Visibility.Visible : Visibility.Collapsed;
        AcceptedCardB.Visibility = _acceptedDbfsB.HasValue ? Visibility.Visible : Visibility.Collapsed;
    }

    public void SetLevel(PerformerSide side, LiveLevel level)
    {
        var meterValue = Math.Clamp((level.SmoothedDbfs + 60) / 60 * 100, 0, 100);
        var label = FormatDbfs(level.SmoothedDbfs);

        if (side == PerformerSide.A)
        {
            LevelA.Text = label;
        }
        else
        {
            LevelB.Text = label;
        }

        SetWaveIntensity(side, meterValue / 100);
    }

    public void ShowWaiting(BattleMatch match, bool juryDecision)
    {
        SetNeutralPanels();
        ResetStageVisuals();
        StatsA.Text = FormatStats(match, PerformerSide.A);
        StatsB.Text = FormatStats(match, PerformerSide.B);
        ShowCenterMessage(AppText.Get(_locale, juryDecision ? "WaitingJury" : "WaitingJudges"), string.Empty);
    }

    public void ShowResult(BattleMatch match)
    {
        ResetStageVisuals();
        PanelA.Opacity = match.Winner?.Id == match.PerformerA?.Id ? 1 : 0.42;
        PanelB.Opacity = match.Winner?.Id == match.PerformerB?.Id ? 1 : 0.42;
        StatsA.Text = FormatStats(match, PerformerSide.A);
        StatsB.Text = FormatStats(match, PerformerSide.B);
        ShowCenterMessage(AppText.Get(_locale, "Winner"), match.Winner?.Name ?? string.Empty);
    }

    public void HideMessage()
    {
        ActionBanner.Visibility = Visibility.Collapsed;
        CenterMessagePanel.Visibility = Visibility.Collapsed;
        CenterCaption.Text = string.Empty;
        CenterMessage.Text = string.Empty;
        ResetStageVisuals();
        SetNeutralPanels();
    }

    private void CloseAudienceButton_OnClick(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private void AudienceWindow_OnPreviewKeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        if (e.Key != System.Windows.Input.Key.Escape)
        {
            return;
        }

        e.Handled = true;
        Close();
    }

    private void SetNeutralPanels()
    {
        PanelA.Opacity = 1;
        PanelB.Opacity = 1;
    }

    private void ShowActionBanner(string text)
    {
        CountdownPanel.Visibility = Visibility.Collapsed;
        CenterMessagePanel.Visibility = Visibility.Collapsed;
        ActionBannerText.Text = text;
        ActionBanner.Visibility = Visibility.Visible;
    }

    private void ShowCenterMessage(string caption, string message)
    {
        CountdownPanel.Visibility = Visibility.Collapsed;
        ActionBanner.Visibility = Visibility.Collapsed;
        CenterCaption.Text = caption;
        CenterMessage.Text = message;
        CenterMessagePanel.Visibility = Visibility.Visible;

        CenterMessageScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
        CenterMessageScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
        CenterMessageScale.ScaleX = 0.9;
        CenterMessageScale.ScaleY = 0.9;
        var reveal = new DoubleAnimation(0.9, 1, TimeSpan.FromMilliseconds(420))
        {
            EasingFunction = new BackEase { Amplitude = 0.22, EasingMode = EasingMode.EaseOut },
        };
        CenterMessageScale.BeginAnimation(ScaleTransform.ScaleXProperty, reveal);
        CenterMessageScale.BeginAnimation(ScaleTransform.ScaleYProperty, reveal);
    }

    private void StartWave(PerformerSide side)
    {
        var wave = side == PerformerSide.A ? WaveA : WaveB;
        var rings = GetRings(side);
        wave.Visibility = Visibility.Visible;
        wave.Opacity = 0.62;

        for (var index = 0; index < rings.Length; index++)
        {
            StartRipple(rings[index], TimeSpan.FromMilliseconds(index * 360));
        }

        SetWaveIntensity(side, 0);
    }

    private static void StartRipple(Ellipse ring, TimeSpan delay)
    {
        var scale = (ScaleTransform)ring.RenderTransform;
        scale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
        scale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
        ring.BeginAnimation(OpacityProperty, null);
        scale.ScaleX = 0.62;
        scale.ScaleY = 0.62;
        ring.Opacity = 0.78;

        var scaleAnimation = new DoubleAnimation(0.62, 1.12, RippleDuration)
        {
            BeginTime = delay,
            RepeatBehavior = RepeatBehavior.Forever,
            EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut },
        };
        var opacityAnimation = new DoubleAnimation(0.78, 0.05, RippleDuration)
        {
            BeginTime = delay,
            RepeatBehavior = RepeatBehavior.Forever,
            EasingFunction = new CubicEase { EasingMode = EasingMode.EaseOut },
        };

        scale.BeginAnimation(ScaleTransform.ScaleXProperty, scaleAnimation);
        scale.BeginAnimation(ScaleTransform.ScaleYProperty, scaleAnimation);
        ring.BeginAnimation(OpacityProperty, opacityAnimation);
    }

    private void SetWaveIntensity(PerformerSide side, double intensity)
    {
        var wave = side == PerformerSide.A ? WaveA : WaveB;
        var core = side == PerformerSide.A ? WaveCoreA : WaveCoreB;
        var coreScale = (ScaleTransform)core.RenderTransform;
        var normalized = Math.Clamp(intensity, 0, 1);
        wave.Opacity = 0.58 + (normalized * 0.42);
        coreScale.ScaleX = 0.88 + (normalized * 0.24);
        coreScale.ScaleY = coreScale.ScaleX;
    }

    private void StopWave(PerformerSide side)
    {
        var wave = side == PerformerSide.A ? WaveA : WaveB;
        var core = side == PerformerSide.A ? WaveCoreA : WaveCoreB;

        foreach (var ring in GetRings(side))
        {
            var scale = (ScaleTransform)ring.RenderTransform;
            scale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
            scale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
            ring.BeginAnimation(OpacityProperty, null);
            scale.ScaleX = 1;
            scale.ScaleY = 1;
            ring.Opacity = 0.2;
        }

        var coreScale = (ScaleTransform)core.RenderTransform;
        coreScale.ScaleX = 1;
        coreScale.ScaleY = 1;
        wave.Visibility = Visibility.Collapsed;
    }

    private Ellipse[] GetRings(PerformerSide side)
    {
        return side == PerformerSide.A
            ? [WaveRingA1, WaveRingA2, WaveRingA3]
            : [WaveRingB1, WaveRingB2, WaveRingB3];
    }

    private void StartMascot(PerformerSide side)
    {
        var mascot = side == PerformerSide.A ? MascotCardA : MascotCardB;
        var transforms = (TransformGroup)mascot.RenderTransform;
        var rotate = (RotateTransform)transforms.Children[1];
        var translate = (TranslateTransform)transforms.Children[2];
        mascot.Visibility = Visibility.Visible;

        rotate.BeginAnimation(RotateTransform.AngleProperty, new DoubleAnimation(-1.2, 1.2, TimeSpan.FromMilliseconds(700))
        {
            AutoReverse = true,
            RepeatBehavior = RepeatBehavior.Forever,
            EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut },
        });
        translate.BeginAnimation(TranslateTransform.YProperty, new DoubleAnimation(6, -8, TimeSpan.FromMilliseconds(700))
        {
            AutoReverse = true,
            RepeatBehavior = RepeatBehavior.Forever,
            EasingFunction = new SineEase { EasingMode = EasingMode.EaseInOut },
        });
    }

    private void StopMascot(PerformerSide side)
    {
        var mascot = side == PerformerSide.A ? MascotCardA : MascotCardB;
        var transforms = (TransformGroup)mascot.RenderTransform;
        var rotate = (RotateTransform)transforms.Children[1];
        var translate = (TranslateTransform)transforms.Children[2];
        rotate.BeginAnimation(RotateTransform.AngleProperty, null);
        translate.BeginAnimation(TranslateTransform.YProperty, null);
        rotate.Angle = 0;
        translate.Y = 0;
        mascot.Visibility = Visibility.Collapsed;
    }

    private void AnimateCountdownTick()
    {
        CountdownSweepRotate.BeginAnimation(RotateTransform.AngleProperty, null);
        CountdownRingRotate.BeginAnimation(RotateTransform.AngleProperty, null);
        CountdownNumberScale.BeginAnimation(ScaleTransform.ScaleXProperty, null);
        CountdownNumberScale.BeginAnimation(ScaleTransform.ScaleYProperty, null);
        CountdownNumber.BeginAnimation(OpacityProperty, null);

        CountdownSweepRotate.BeginAnimation(
            RotateTransform.AngleProperty,
            new DoubleAnimation(-18, 342, TimeSpan.FromSeconds(1))
            {
                EasingFunction = new CubicEase { EasingMode = EasingMode.EaseInOut },
            });
        CountdownRingRotate.BeginAnimation(
            RotateTransform.AngleProperty,
            new DoubleAnimation(0, -42, TimeSpan.FromSeconds(1)));

        var numberScale = new DoubleAnimation(1.12, 0.9, TimeSpan.FromMilliseconds(900))
        {
            EasingFunction = new CubicEase { EasingMode = EasingMode.EaseIn },
        };
        CountdownNumberScale.BeginAnimation(ScaleTransform.ScaleXProperty, numberScale);
        CountdownNumberScale.BeginAnimation(ScaleTransform.ScaleYProperty, numberScale);
        CountdownNumber.BeginAnimation(
            OpacityProperty,
            new DoubleAnimation(1, 0.7, TimeSpan.FromMilliseconds(900)));
    }

    private void ResetStageVisuals()
    {
        CountdownPanel.Visibility = Visibility.Collapsed;
        AcceptedCardA.Visibility = Visibility.Collapsed;
        AcceptedCardB.Visibility = Visibility.Collapsed;
        StopWave(PerformerSide.A);
        StopWave(PerformerSide.B);
        StopMascot(PerformerSide.A);
        StopMascot(PerformerSide.B);
    }

    private void UpdateAcceptedCardText()
    {
        AcceptedLabelA.Text = AppText.Get(_locale, "Accepted");
        AcceptedLabelB.Text = AppText.Get(_locale, "Accepted");
        AcceptedLevelA.Text = _acceptedDbfsA is double acceptedA ? FormatDbfs(acceptedA) : string.Empty;
        AcceptedLevelB.Text = _acceptedDbfsB is double acceptedB ? FormatDbfs(acceptedB) : string.Empty;
    }

    private string FormatDbfs(double value)
    {
        var culture = _locale == "uk" ? CultureInfo.GetCultureInfo("uk-UA") : CultureInfo.InvariantCulture;
        return $"{value.ToString("F1", culture)} dBFS";
    }

    private string FormatStats(BattleMatch match, PerformerSide side)
    {
        var jury = side == PerformerSide.A ? match.JudgeVotes.VotesA : match.JudgeVotes.VotesB;
        var audience = side == PerformerSide.A ? match.Audience?.PercentageA : match.Audience?.PercentageB;
        var combined = side == PerformerSide.A ? match.Combined?.PercentageA : match.Combined?.PercentageB;
        var audienceLabel = audience is null ? "—" : $"{audience:F1}%";
        var combinedLabel = combined is null ? "—" : $"{combined:F1}%";
        return $"{AppText.Get(_locale, "Jury")} {jury}/{match.JudgeVotes.Required}   ·   {AppText.Get(_locale, "Audience")} {audienceLabel}   ·   {AppText.Get(_locale, "Combined")} {combinedLabel}";
    }
}
